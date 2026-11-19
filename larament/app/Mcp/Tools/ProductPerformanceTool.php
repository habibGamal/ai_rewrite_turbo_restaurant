<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\ProductType;
use App\Filament\Pages\Reports\ProductsSalesPerformanceReport;
use App\Mcp\Support\ArabicText;
use App\Mcp\Support\Period;
use App\Models\Category;
use App\Models\Product;
use App\Services\ProductsSalesReportService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('product_performance')]
#[Title('Product performance')]
#[Description(<<<'TEXT'
Ranks products or categories by what they sold in a period: net quantity, net sales, net profit, margin or returns, with share of sales and an ABC class. rank_by=no_sales lists products that sold nothing.
Use for best/worst sellers, most profitable items, items losing money, category shares, what to promote or drop.
Not for one product's full detail or for stock levels.
Examples: «ما أكثر عشرة أصناف مبيعًا هذا الشهر؟», «ما الأصناف التي لم تُبع منذ ثلاثين يومًا؟», "Which category brings the most profit?".
TEXT)]
#[IsReadOnly]
class ProductPerformanceTool extends BusinessTool
{
    public const RANKS = ['net_sales', 'net_quantity', 'net_profit', 'margin', 'returns', 'lowest_sales', 'no_sales'];

    public const LEVELS = ['product', 'main_category', 'sub_category'];

    private readonly ProductsSalesReportService $products;

    /** Services come from the container when the tool is built without them. */
    public function __construct(?ProductsSalesReportService $products = null)
    {
        $this->products = $products ?? app(ProductsSalesReportService::class);
    }

    protected function reportPages(): array
    {
        return [ProductsSalesPerformanceReport::class];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'level' => $schema->string()->enum(self::LEVELS)->description('Rank products (default) or roll up to categories.'),
            'rank_by' => $schema->string()->enum(self::RANKS)
                ->description('Default net_sales (highest first). lowest_sales = sold least but not zero; no_sales = sold nothing (products only).'),
            ...$this->periodSchema($schema, compare: false),
            'category' => $schema->string()->description('Only this category (name or id); a main category includes its sub-categories.'),
            'limit' => $schema->integer()->description('Rows to return, default 10, max '.self::MAX_LIMIT.'.'),
        ];
    }

    protected function run(Request $request): array
    {
        $period = $this->period($request, 'this_month', 'none');
        $level = (string) ($request->get('level') ?? 'product');
        $rankBy = (string) ($request->get('rank_by') ?? 'net_sales');
        if (! in_array($level, self::LEVELS, true)) {
            throw new InvalidArgumentException('level must be one of: '.implode(', ', self::LEVELS).'.');
        }
        if (! in_array($rankBy, self::RANKS, true)) {
            throw new InvalidArgumentException('rank_by must be one of: '.implode(', ', self::RANKS).'.');
        }
        if ($rankBy === 'no_sales' && $level !== 'product') {
            throw new InvalidArgumentException('rank_by=no_sales works for products only.');
        }

        [$mainId, $subId, $categoryName] = $this->category($request->get('category'));
        $limit = $this->limit($request);
        $from = $period->from->toDateString();
        $to = $period->to->toDateString();

        $result = match (true) {
            $rankBy === 'no_sales' => $this->unsold($from, $to, $mainId, $subId, $limit),
            $level === 'product' => $this->rankProducts($from, $to, $mainId, $subId, $rankBy, $limit),
            default => $this->rankCategories($from, $to, $mainId, $subId, $level, $rankBy, $limit),
        };

        return $this->envelope(
            $period,
            ['level' => $level, 'rank_by' => $rankBy, ...$result],
            [
                'net_quantity' => 'Quantity sold minus quantity returned.',
                'net_sales' => 'Item value (before the order-level discount) minus refunds.',
                'net_profit' => 'Item value minus item cost at the time of sale, minus profit lost to refunds.',
                'margin' => 'net_profit / net_sales, percent.',
                'share' => 'Percent of net_sales of all rows in scope.',
                'abc' => 'A = top items making 80% of sales, B = next 15%, C = last 5%.',
                'rows_in_scope' => 'How many products/categories had sales before the limit.',
            ],
            ['Item sales here are before the order-level discount, so they add up to more than sales_summary net_sales.'],
            array_filter(['category' => $categoryName]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function rankProducts(string $from, string $to, ?int $mainId, ?int $subId, string $rankBy, int $limit): array
    {
        $rows = $this->products->getProductsSalesPerformanceQuery($from, $to, null, $mainId, $subId)
            ->get()
            ->map(fn ($row) => [
                'product_id' => (int) $row->id,
                'name' => (string) $row->name,
                'category' => $row->sub_category_name ? $row->main_category_name.' / '.$row->sub_category_name : (string) $row->main_category_name,
                'price' => round((float) $row->price, 2),
                'net_quantity' => round((float) $row->net_quantity, 3),
                'net_sales' => round((float) $row->net_sales, 2),
                'net_profit' => round((float) $row->net_profit, 2),
                'margin' => self::share((float) $row->net_profit, (float) $row->net_sales),
                'returned_quantity' => round((float) $row->returned_quantity, 3),
                'refunds' => round((float) $row->total_refunds, 2),
            ])
            ->all();

        return $this->ranked($rows, $rankBy, $limit);
    }

    /**
     * @return array<string, mixed>
     */
    private function rankCategories(string $from, string $to, ?int $mainId, ?int $subId, string $level, string $rankBy, int $limit): array
    {
        if ($rankBy === 'returns') {
            throw new InvalidArgumentException('rank_by=returns works for products only.');
        }

        $rows = $this->products->getCategoryPerformance($from, $to, null, $mainId, $subId, $level === 'sub_category' ? 'secondary' : 'main')
            ->get()
            ->filter(fn ($row) => (float) $row->total_quantity > 0)
            ->map(fn ($row) => [
                'category_id' => (int) $row->id,
                'name' => (string) $row->category_name,
                'parent' => $level === 'sub_category' ? (string) ($row->parent_category_name ?? '') : null,
                'products_count' => (int) $row->products_count,
                'net_quantity' => round((float) $row->total_quantity, 3),
                'net_sales' => round((float) $row->total_sales, 2),
                'net_profit' => round((float) $row->total_profit, 2),
                'margin' => self::share((float) $row->total_profit, (float) $row->total_sales),
            ])
            ->map(fn (array $row) => array_filter($row, fn ($value) => $value !== null))
            ->values()
            ->all();

        $result = $this->ranked($rows, $rankBy, $limit);
        $result['note'] = 'Category figures are before refunds.';

        return $result;
    }

    /**
     * Sort, add share and ABC class over every row, then keep the top rows.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function ranked(array $rows, string $rankBy, int $limit): array
    {
        $total = array_sum(array_column($rows, 'net_sales'));

        usort($rows, fn (array $a, array $b) => $b['net_sales'] <=> $a['net_sales']);
        $running = 0.0;
        foreach ($rows as $i => $row) {
            $running += $row['net_sales'];
            $cumulative = $total != 0 ? $running / $total * 100 : 100;
            $rows[$i]['share'] = self::share($row['net_sales'], $total);
            $rows[$i]['abc'] = $cumulative <= 80 || $i === 0 ? 'A' : ($cumulative <= 95 ? 'B' : 'C');
        }

        $key = match ($rankBy) {
            'returns' => 'returned_quantity',
            'lowest_sales' => 'net_sales',
            default => $rankBy,
        };
        usort($rows, $rankBy === 'lowest_sales'
            ? fn (array $a, array $b) => $a[$key] <=> $b[$key]
            : fn (array $a, array $b) => $b[$key] <=> $a[$key]);
        if ($rankBy === 'returns') {
            $rows = array_values(array_filter($rows, fn (array $row) => $row['returned_quantity'] > 0));
        }

        return [
            'rows_in_scope' => count($rows),
            'total_net_sales' => round($total, 2),
            'rows' => array_slice($rows, 0, $limit),
            'truncated' => count($rows) > $limit,
        ];
    }

    /**
     * Sellable products with no completed sale in the period.
     *
     * @return array<string, mixed>
     */
    private function unsold(string $from, string $to, ?int $mainId, ?int $subId, int $limit): array
    {
        $sold = $this->products->getProductsSalesPerformanceQuery($from, $to, null, $mainId, $subId)
            ->get()
            ->filter(fn ($row) => (float) $row->total_quantity > 0)
            ->pluck('id')
            ->all();

        $query = Product::query()
            ->whereNot('type', ProductType::RawMaterial)
            ->whereNotIn('id', $sold === [] ? [0] : $sold)
            ->when($subId !== null, fn ($q) => $q->where('category_id', $subId))
            ->when($mainId !== null && $subId === null, fn ($q) => $q->whereIn(
                'category_id',
                Category::query()->where('id', $mainId)->orWhere('parent_id', $mainId)->select('id'),
            ))
            ->with('category:id,name');

        $count = (clone $query)->count();
        $rows = $query->orderBy('name')->limit($limit)->get()->map(fn (Product $product) => [
            'product_id' => $product->id,
            'name' => $product->name,
            'category' => $product->category?->name,
            'price' => round((float) $product->price, 2),
        ])->all();

        return ['rows_in_scope' => $count, 'rows' => $rows, 'truncated' => $count > $limit];
    }

    /**
     * Resolve a category argument (id or name) to the report's main/sub ids.
     *
     * @return array{0: ?int, 1: ?int, 2: ?string}
     */
    private function category(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [null, null, null];
        }

        $category = is_numeric($value)
            ? Category::query()->find((int) $value)
            : ArabicText::whereMatches(Category::query(), 'name', (string) $value)->orderByRaw('LENGTH(name)')->first();

        if ($category === null) {
            throw new InvalidArgumentException("No category matches \"{$value}\". Use find_entities with kinds=[category] to list them.");
        }

        return $category->parent_id === null
            ? [$category->id, null, $category->name]
            : [null, $category->id, $category->name];
    }
}
