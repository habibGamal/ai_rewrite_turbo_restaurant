<?php

namespace App\Labels;

use App\Models\LabelTemplate;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A print job: which labels to print with which design. Kept in the cache
 * for a couple of hours and handed out as signed links, so the Windows
 * print app can fetch it without a login.
 *
 * Labels are laid out into printed rows (as many labels per row as the
 * design's columns) and runs of identical rows are stored once with a copy
 * count: 500 labels of one product are one image printed 500 times.
 */
class LabelPrintJobs
{
    public const MAX_LABELS = 10000;

    private const TTL_MINUTES = 120;

    public function __construct(
        private readonly LabelRenderer $renderer,
        private readonly LabelImageMaker $images,
    ) {}

    /**
     * @param  array<int, int>  $copiesByProduct  product id => number of labels
     */
    public function forProducts(LabelTemplate $template, array $copiesByProduct): string
    {
        $products = Product::query()->with('group')->whereKey(array_keys($copiesByProduct))->get()->keyBy('id');
        $shopName = LabelData::shopName();

        $entries = [];
        foreach ($copiesByProduct as $productId => $copies) {
            $product = $products->get($productId);
            if ($product === null || (int) $copies < 1) {
                continue;
            }
            $entries[] = [LabelData::fromProduct($product, $shopName), (int) $copies];
        }

        return $this->create($template, $entries);
    }

    /** One sample label, for checking a design on the printer. */
    public function sample(LabelTemplate $template): string
    {
        return $this->create($template, [[LabelData::sample(), max(1, $template->columns)]]);
    }

    /**
     * @param  list<array{0: LabelData, 1: int}>  $entries
     */
    public function create(LabelTemplate $template, array $entries): string
    {
        $total = array_sum(array_map(fn (array $entry): int => $entry[1], $entries));
        if ($total < 1) {
            throw ValidationException::withMessages(['labels' => 'لا توجد ملصقات للطباعة.']);
        }
        if ($total > self::MAX_LABELS) {
            throw ValidationException::withMessages(['labels' => 'أقصى عدد ملصقات في المرة الواحدة '.self::MAX_LABELS.'.']);
        }

        $columns = max(1, $template->columns);
        $labels = [];
        $rows = [];
        $current = [];

        $pushRow = function (array $row, int $copies) use (&$rows): void {
            $last = array_key_last($rows);
            if ($last !== null && $rows[$last]['labels'] === $row) {
                $rows[$last]['copies'] += $copies;
            } else {
                $rows[] = ['labels' => $row, 'copies' => $copies];
            }
        };

        foreach ($entries as [$data, $copies]) {
            $key = $data->key();
            $labels[$key] = [
                'shop' => $data->shopName,
                'name' => $data->productName,
                'variant' => $data->variant,
                'barcode' => $data->barcode,
                'price' => $data->price,
            ];

            // Fill the row left open by the previous product first.
            while ($copies > 0 && $current !== []) {
                $current[] = $key;
                $copies--;
                if (count($current) === $columns) {
                    $pushRow($current, 1);
                    $current = [];
                }
            }

            $fullRows = intdiv($copies, $columns);
            if ($fullRows > 0) {
                $pushRow(array_fill(0, $columns, $key), $fullRows);
            }
            $current = array_fill(0, $copies % $columns, $key);
        }
        if ($current !== []) {
            $pushRow($current, 1);
        }

        $id = (string) Str::uuid();
        Cache::put(self::cacheKey($id), [
            'template_id' => $template->id,
            'template_version' => $template->updated_at?->getTimestamp(),
            'labels' => $labels,
            'rows' => $rows,
            'total' => $total,
        ], now()->addMinutes(self::TTL_MINUTES));

        return $id;
    }

    /**
     * @return array{template_id: int, labels: array<string, array<string, mixed>>, rows: list<array{labels: list<string>, copies: int}>, total: int}|null
     */
    public function find(string $id): ?array
    {
        $job = Cache::get(self::cacheKey($id));

        return is_array($job) ? $job : null;
    }

    public function manifestUrl(string $id): string
    {
        return URL::temporarySignedRoute('labels.jobs.manifest', now()->addMinutes(self::TTL_MINUTES), ['job' => $id]);
    }

    /**
     * What the print app needs: the page size and each page image with how
     * many times to print it.
     *
     * @return array<string, mixed>
     */
    public function manifest(string $id): ?array
    {
        $job = $this->find($id);
        $template = $job === null ? null : LabelTemplate::find($job['template_id']);
        if ($job === null || $template === null) {
            return null;
        }

        $expires = now()->addMinutes(self::TTL_MINUTES);

        return [
            'job' => $id,
            'template' => $template->name,
            'page' => [
                'width_mm' => $template->rowWidthMm(),
                'height_mm' => $template->height_mm,
                'dpi' => $template->dpi,
                'width_dots' => $template->dots($template->rowWidthMm()),
                'height_dots' => $template->dots($template->height_mm),
            ],
            'total_labels' => $job['total'],
            'total_pages' => array_sum(array_column($job['rows'], 'copies')),
            'pages' => array_map(fn (array $row, int $index): array => [
                'image' => URL::temporarySignedRoute('labels.jobs.page', $expires, ['job' => $id, 'page' => $index]),
                'copies' => $row['copies'],
            ], $job['rows'], array_keys($job['rows'])),
        ];
    }

    /**
     * PNG bytes of one page (printed row) of the job, or null when the job
     * or the page is gone.
     */
    public function pagePng(string $id, int $page): ?string
    {
        $job = $this->find($id);
        $row = $job['rows'][$page] ?? null;
        $template = $job === null ? null : LabelTemplate::find($job['template_id']);
        if ($row === null || $template === null) {
            return null;
        }

        $cacheKey = 'labels:png:'.md5(json_encode([$template->id, $template->updated_at?->getTimestamp(), $row['labels']]) ?: '');

        return Cache::remember($cacheKey, now()->addMinutes(self::TTL_MINUTES), function () use ($template, $job, $row): string {
            return $this->images->png($template, $this->renderer->rowHtml($template, $this->rowData($job, $row['labels'])));
        });
    }

    /**
     * @param  array<string, mixed>  $job
     * @param  list<string>  $keys
     * @return list<LabelData>
     */
    private function rowData(array $job, array $keys): array
    {
        return array_map(function (string $key) use ($job): LabelData {
            $label = $job['labels'][$key];

            return new LabelData(
                shopName: (string) $label['shop'],
                productName: (string) $label['name'],
                variant: (string) $label['variant'],
                barcode: (string) $label['barcode'],
                price: (float) $label['price'],
            );
        }, $keys);
    }

    private static function cacheKey(string $id): string
    {
        return 'labels:job:'.$id;
    }
}
