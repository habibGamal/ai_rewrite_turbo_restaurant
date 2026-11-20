<?php

namespace App\Services\Reports\Inventory;

use Carbon\CarbonImmutable;

/**
 * The inventory movements report's filters: the period, what to compare it
 * with, and the warehouse / category / product narrowing. Null = all.
 */
final class InventoryReportFilters
{
    public const COMPARE_PREVIOUS = 'previous';

    public const COMPARE_LAST_YEAR = 'last_year';

    public const COMPARE_NONE = 'none';

    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly string $compare = self::COMPARE_PREVIOUS,
        public readonly ?int $warehouseId = null,
        public readonly ?int $mainCategoryId = null,
        public readonly ?int $subCategoryId = null,
        public readonly ?int $productId = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $filters  the page's filters form state
     */
    public static function fromArray(?array $filters): self
    {
        $filters ??= [];
        $start = CarbonImmutable::parse($filters['startDate'] ?? now()->subDays(29)->toDateString())->startOfDay();
        $end = CarbonImmutable::parse($filters['endDate'] ?? now()->toDateString())->startOfDay();
        if ($end->lessThan($start)) {
            [$start, $end] = [$end, $start];
        }

        $compare = $filters['compareTo'] ?? self::COMPARE_PREVIOUS;
        if (! in_array($compare, [self::COMPARE_PREVIOUS, self::COMPARE_LAST_YEAR, self::COMPARE_NONE], true)) {
            $compare = self::COMPARE_PREVIOUS;
        }

        return new self(
            $start,
            $end,
            $compare,
            self::id($filters['warehouse_id'] ?? null),
            self::id($filters['main_category_id'] ?? null),
            self::id($filters['sub_category_id'] ?? null),
            self::id($filters['product_id'] ?? null),
        );
    }

    /**
     * @return array<string, string>
     */
    public static function compareOptions(): array
    {
        return [
            self::COMPARE_PREVIOUS => 'الفترة السابقة بنفس الطول',
            self::COMPARE_LAST_YEAR => 'نفس الفترة السنة اللي فاتت',
            self::COMPARE_NONE => 'بدون مقارنة',
        ];
    }

    private static function id(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    public function days(): int
    {
        return (int) $this->start->diffInDays($this->end) + 1;
    }

    /** The period to compare with, or null when comparing is off. */
    public function comparison(): ?self
    {
        return match ($this->compare) {
            self::COMPARE_PREVIOUS => $this->withPeriod($this->start->subDays($this->days()), $this->start->subDay()),
            self::COMPARE_LAST_YEAR => $this->withPeriod($this->start->subYear(), $this->end->subYear()),
            default => null,
        };
    }

    public function withPeriod(CarbonImmutable $start, CarbonImmutable $end): self
    {
        return new self($start, $end, self::COMPARE_NONE, $this->warehouseId, $this->mainCategoryId, $this->subCategoryId, $this->productId);
    }

    public function comparisonWord(): string
    {
        return $this->compare === self::COMPARE_LAST_YEAR ? 'السنة اللي فاتت' : 'الفترة السابقة';
    }

    public function startDate(): string
    {
        return $this->start->toDateString();
    }

    public function endDate(): string
    {
        return $this->end->toDateString();
    }

    /**
     * The same narrowing as page filters for another report (the item ledger
     * reads these keys from its URL).
     *
     * @return array<string, mixed>
     */
    public function toLedgerFilters(?int $productId = null): array
    {
        return array_filter([
            'presetPeriod' => 'custom',
            'startDate' => $this->startDate(),
            'endDate' => $this->endDate(),
            'warehouse_id' => $this->warehouseId,
            'main_category_id' => $this->mainCategoryId,
            'sub_category_id' => $this->subCategoryId,
            'product_id' => $productId ?? $this->productId,
        ], fn ($value) => $value !== null);
    }
}
