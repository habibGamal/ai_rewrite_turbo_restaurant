<?php

namespace App\Services\Reports\Channels;

use Carbon\CarbonImmutable;

/**
 * The channels report's filters: the period, what to compare it with and
 * which channels (order type codes) to include. No channels = all of them.
 */
final class ChannelReportFilters
{
    public const COMPARE_PREVIOUS = 'previous';

    public const COMPARE_LAST_YEAR = 'last_year';

    public const COMPARE_NONE = 'none';

    /**
     * @param  list<string>  $channels
     */
    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly string $compare = self::COMPARE_PREVIOUS,
        public readonly array $channels = [],
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

        $channels = array_values(array_filter(array_map('strval', (array) ($filters['channels'] ?? []))));

        return new self($start, $end, $compare, $channels);
    }

    public static function compareOptions(): array
    {
        return [
            self::COMPARE_PREVIOUS => 'الفترة السابقة بنفس الطول',
            self::COMPARE_LAST_YEAR => 'نفس الفترة السنة اللي فاتت',
            self::COMPARE_NONE => 'بدون مقارنة',
        ];
    }

    public function days(): int
    {
        return (int) $this->start->diffInDays($this->end) + 1;
    }

    /** The period to compare with, or null when comparing is off. */
    public function comparison(): ?self
    {
        return match ($this->compare) {
            self::COMPARE_PREVIOUS => new self(
                $this->start->subDays($this->days()),
                $this->start->subDay(),
                self::COMPARE_NONE,
                $this->channels,
            ),
            self::COMPARE_LAST_YEAR => new self(
                $this->start->subYear(),
                $this->end->subYear(),
                self::COMPARE_NONE,
                $this->channels,
            ),
            default => null,
        };
    }

    public function startDate(): string
    {
        return $this->start->toDateString();
    }

    public function endDate(): string
    {
        return $this->end->toDateString();
    }

    public function label(): string
    {
        return $this->start->equalTo($this->end)
            ? $this->start->format('d/m/Y')
            : $this->start->format('d/m/Y').' - '.$this->end->format('d/m/Y');
    }
}
