<?php

namespace App\Services\Products;

use App\Enums\ProductType;
use App\Enums\SettingKey;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductGroup;
use App\Models\ProductOptionValue;
use App\Services\SettingsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Builds and maintains the SKUs (products rows) of a retail product group.
 *
 * A variant is identified by the set of option values it carries, one per
 * option axis. Groups may have any number of axes; the cartesian product is
 * capped per generate run so a typo cannot create thousands of rows.
 */
class ProductVariantService
{
    /** Most SKUs one generate run may create. */
    public const MAX_COMBINATIONS = 500;

    /** Weighed (scale) items are never variants. */
    public const WEIGHT_UNITS = ['kg', 'gram'];

    public function __construct(private readonly SettingsService $settings) {}

    public function assertGroupable(ProductGroup|Product $item): void
    {
        if (in_array($item->unit, self::WEIGHT_UNITS, true)) {
            throw ValidationException::withMessages([
                'unit' => 'المنتجات الموزونة لا يمكن أن تكون مقاسات/ألوان لموديل.',
            ]);
        }

        if ($item instanceof Product && $item->type !== ProductType::Consumable) {
            throw ValidationException::withMessages([
                'type' => 'متغيرات الموديل يجب أن تكون منتجات استهلاكية (تجزئة).',
            ]);
        }
    }

    /**
     * Every combination of the group's option values, in option/value order,
     * with the SKU that already has it (if any).
     *
     * @return Collection<int, array{key: string, values: Collection<int, ProductOptionValue>, label: string, product: Product|null}>
     */
    public function combinations(ProductGroup $group): Collection
    {
        $group->load(['options.values']);
        $axes = $group->options
            ->map(fn ($option) => $option->values)
            ->filter(fn (Collection $values) => $values->isNotEmpty())
            ->values();

        if ($axes->isEmpty()) {
            return collect();
        }

        $count = $axes->reduce(fn (int $carry, Collection $values) => $carry * $values->count(), 1);
        if ($count > self::MAX_COMBINATIONS) {
            throw ValidationException::withMessages([
                'options' => sprintf('عدد التركيبات (%d) أكبر من الحد المسموح (%d). قلل القيم أو قسم الموديل.', $count, self::MAX_COMBINATIONS),
            ]);
        }

        /** @var array<int, array<int, ProductOptionValue>> $combos */
        $combos = [[]];
        foreach ($axes as $values) {
            $next = [];
            foreach ($combos as $combo) {
                foreach ($values as $value) {
                    $next[] = [...$combo, $value];
                }
            }
            $combos = $next;
        }

        $existing = $this->variantsByKey($group);

        return collect($combos)->map(function (array $values) use ($existing) {
            $values = collect($values);
            $key = self::keyFor($values->pluck('id')->all());

            return [
                'key' => $key,
                'values' => $values,
                'label' => self::labelFor($values),
                'product' => $existing->get($key),
            ];
        });
    }

    /**
     * Variants whose value set no longer matches a full combination, e.g.
     * after an axis was added. They keep selling; the admin may deactivate them.
     *
     * @return Collection<int, Product>
     */
    public function incompleteVariants(ProductGroup $group): Collection
    {
        $keys = $this->combinations($group)->pluck('key')->flip();

        return $this->variantsByKey($group)->reject(fn ($product, $key) => $keys->has($key))->values();
    }

    /**
     * Create the SKUs for every combination that does not exist yet.
     * Re-running after adding size XL creates only the XL rows.
     *
     * @return Collection<int, Product> the newly created SKUs
     */
    public function generate(ProductGroup $group): Collection
    {
        $this->assertGroupable($group);

        return DB::transaction(function () use ($group) {
            $created = collect();

            foreach ($this->combinations($group) as $combo) {
                if ($combo['product'] !== null) {
                    continue;
                }

                $product = Product::create([
                    'product_group_id' => $group->id,
                    'category_id' => $group->category_id,
                    'name' => self::nameFor($group->name, $combo['label']),
                    'variant_label' => $combo['label'],
                    'sku' => $this->uniqueSku($group, $combo['values']),
                    'price' => $group->base_price,
                    'has_custom_price' => false,
                    'cost' => $group->base_cost,
                    'type' => ProductType::Consumable,
                    'unit' => $group->unit,
                    'is_active' => true,
                    'legacy' => false,
                ]);

                $product->optionValues()->sync($combo['values']->pluck('id')->all());
                $created->push($product);
            }

            return $created;
        });
    }

    /**
     * Push the group's shared fields to its variants: name, category, unit,
     * and price for every SKU without its own override.
     */
    public function syncGroupFields(ProductGroup $group): void
    {
        $group->variants()->with('optionValues.option')->get()->each(function (Product $product) use ($group) {
            $product->fill([
                'name' => self::nameFor($group->name, (string) $product->variant_label),
                'category_id' => $group->category_id,
                'unit' => $group->unit,
            ]);

            if (! $product->has_custom_price) {
                $product->price = $group->base_price;
            }

            if ($product->isDirty()) {
                $product->save();
            }
        });
    }

    /**
     * Rebuild each variant's cached label (and name) from its option values,
     * after a value was renamed or the axes were reordered.
     */
    public function refreshLabels(ProductGroup $group): void
    {
        $group->variants()->with('optionValues.option')->get()->each(function (Product $product) use ($group) {
            $label = self::labelFor($product->optionValues);
            $product->fill([
                'variant_label' => $label,
                'name' => self::nameFor($group->name, $label),
            ]);

            if ($product->isDirty()) {
                $product->save();
            }
        });
    }

    /**
     * Give one SKU its own price, or pass null to follow the group price again.
     */
    public function setVariantPrice(Product $product, float|int|string|null $price): void
    {
        if ($price === null || $price === '') {
            $group = ProductGroup::find($product->product_group_id);
            if ($group !== null) {
                $product->price = $group->base_price;
            }
            $product->has_custom_price = false;
        } else {
            $product->price = $price;
            $product->has_custom_price = true;
        }

        $product->save();
    }

    /**
     * Throws when any SKU uses this value; deactivate those SKUs instead.
     */
    public function assertValueDeletable(ProductOptionValue $value): void
    {
        if ($value->products()->exists()) {
            throw ValidationException::withMessages([
                'value' => sprintf('القيمة "%s" مستخدمة في منتجات؛ أوقف المنتجات بدلاً من حذف القيمة.', $value->value),
            ]);
        }
    }

    /**
     * A free in-store EAN-13 (prefix 2x) that no product uses yet and that
     * does not start with the scale label prefix.
     */
    public function generateBarcode(): string
    {
        $scalePrefix = (string) $this->settings->get(SettingKey::SCALE_BARCODE_PREFIX->value, '23');
        $prefix = '29';
        foreach (['29', '28', '27', '26', '25', '24', '22', '21', '20'] as $candidate) {
            if ($scalePrefix === '' || (! str_starts_with($candidate, $scalePrefix) && ! str_starts_with($scalePrefix, $candidate))) {
                $prefix = $candidate;
                break;
            }
        }

        do {
            $body = $prefix.str_pad((string) random_int(0, 9_999_999_999), 10, '0', STR_PAD_LEFT);
            $code = $body.self::ean13CheckDigit($body);
        } while (ProductBarcode::where('barcode', $code)->exists() || Product::where('barcode', $code)->exists());

        return $code;
    }

    /**
     * Give every variant of the group that has no barcode a generated one.
     *
     * @return int how many barcodes were assigned
     */
    public function assignMissingBarcodes(ProductGroup $group): int
    {
        $count = 0;
        $group->variants()->where(fn ($q) => $q->whereNull('barcode')->orWhere('barcode', ''))->get()
            ->each(function (Product $product) use (&$count) {
                $product->barcode = $this->generateBarcode();
                $product->save();
                $count++;
            });

        return $count;
    }

    public static function ean13CheckDigit(string $twelveDigits): int
    {
        $sum = 0;
        foreach (str_split($twelveDigits) as $i => $digit) {
            $sum += ((int) $digit) * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - ($sum % 10)) % 10;
    }

    /** @param  array<int, int>  $valueIds */
    public static function keyFor(array $valueIds): string
    {
        $ids = array_map('intval', $valueIds);
        sort($ids);

        return implode('-', $ids);
    }

    /**
     * "أسود / M": values ordered by their option's position.
     *
     * @param  Collection<int, ProductOptionValue>  $values
     */
    public static function labelFor(Collection $values): string
    {
        return $values
            ->sortBy(fn (ProductOptionValue $value) => [$value->option->position ?? 0, $value->product_option_id])
            ->pluck('value')
            ->implode(' / ');
    }

    public static function nameFor(string $groupName, string $label): string
    {
        return $label === '' ? $groupName : $groupName.' - '.$label;
    }

    /** @return Collection<string, Product> */
    private function variantsByKey(ProductGroup $group): Collection
    {
        return $group->variants()->with('optionValues')->get()
            ->keyBy(fn (Product $product) => self::keyFor($product->optionValues->pluck('id')->all()));
    }

    /** @param  Collection<int, ProductOptionValue>  $values */
    private function uniqueSku(ProductGroup $group, Collection $values): ?string
    {
        $prefix = trim((string) $group->sku_prefix);
        if ($prefix === '') {
            return null;
        }

        $base = mb_substr(collect([mb_strtoupper($prefix)])
            ->merge($values->map(fn (ProductOptionValue $value) => $value->skuCode()))
            ->implode('-'), 0, 60);

        $sku = $base;
        $n = 2;
        while (Product::where('sku', $sku)->exists()) {
            $sku = $base.'-'.$n++;
        }

        return $sku;
    }
}
