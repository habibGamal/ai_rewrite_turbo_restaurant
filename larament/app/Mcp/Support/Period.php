<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use App\Services\Reports\Channels\ChannelReportFilters;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A report period for the MCP tools: a named preset or explicit dates, plus
 * the period to compare it with. Dates are calendar days in the app's
 * timezone, the same days the Filament reports filter by.
 */
final class Period
{
    public const PRESETS = [
        'today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month',
        'last_7_days', 'last_30_days', 'last_90_days', 'this_year', 'last_year',
    ];

    public const COMPARES = ['previous_period', 'same_period_last_year', 'none'];

    public const MAX_DAYS = 366;

    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $label,
        public readonly string $compare = 'previous_period',
    ) {}

    /**
     * @param  array<string, mixed>  $arguments  the tool call's arguments (period, from, to, compare_to)
     */
    public static function fromArguments(array $arguments, string $defaultPreset = 'this_month', string $defaultCompare = 'previous_period'): self
    {
        $compare = (string) ($arguments['compare_to'] ?? $defaultCompare);
        if (! in_array($compare, self::COMPARES, true)) {
            throw new InvalidArgumentException('compare_to must be one of: '.implode(', ', self::COMPARES).'.');
        }

        $from = $arguments['from'] ?? null;
        $to = $arguments['to'] ?? null;

        if ($from !== null || $to !== null) {
            $start = self::parseDate((string) ($from ?? $to), 'from');
            $end = self::parseDate((string) ($to ?? $from), 'to');
            if ($end->lessThan($start)) {
                [$start, $end] = [$end, $start];
            }
            $period = new self($start, $end, 'custom', $compare);
        } else {
            $period = self::preset((string) ($arguments['period'] ?? $defaultPreset), $compare);
        }

        if ($period->days() > self::MAX_DAYS) {
            throw new InvalidArgumentException('Periods are limited to '.self::MAX_DAYS.' days. Ask for a shorter period.');
        }

        return $period;
    }

    public static function preset(string $name, string $compare = 'previous_period'): self
    {
        $today = CarbonImmutable::now()->startOfDay();
        // Weeks start on Saturday, like the reports' weekly buckets.
        $weekStart = $today->subDays(($today->dayOfWeek + 1) % 7);

        [$start, $end] = match ($name) {
            'today' => [$today, $today],
            'yesterday' => [$today->subDay(), $today->subDay()],
            'this_week' => [$weekStart, $today],
            'last_week' => [$weekStart->subWeek(), $weekStart->subDay()],
            'this_month' => [$today->startOfMonth(), $today],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'last_7_days' => [$today->subDays(6), $today],
            'last_30_days' => [$today->subDays(29), $today],
            'last_90_days' => [$today->subDays(89), $today],
            'this_year' => [$today->startOfYear(), $today],
            'last_year' => [$today->subYear()->startOfYear(), $today->subYear()->endOfYear()->startOfDay()],
            default => throw new InvalidArgumentException('period must be one of: '.implode(', ', self::PRESETS).', or give from/to dates (YYYY-MM-DD).'),
        };

        return new self($start, $end, $name, $compare);
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /** The period to compare with, or null when compare is none. */
    public function comparison(): ?self
    {
        return match ($this->compare) {
            'previous_period' => new self($this->from->subDays($this->days()), $this->from->subDay(), 'previous_period', 'none'),
            'same_period_last_year' => new self($this->from->subYear(), $this->to->subYear(), 'same_period_last_year', 'none'),
            default => null,
        };
    }

    /** Includes today, so its figures can still change. */
    public function isOpen(): bool
    {
        return $this->to->greaterThanOrEqualTo(CarbonImmutable::now()->startOfDay());
    }

    /**
     * @return array{from: string, to: string, label: string, days: int, includes_today: bool}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'label' => $this->label,
            'days' => $this->days(),
            'includes_today' => $this->isOpen(),
        ];
    }

    /**
     * The channels report's filters for this period, so tools reuse its
     * queries and agree with the report page.
     *
     * @param  list<string>  $orderTypes
     */
    public function channelFilters(array $orderTypes = []): ChannelReportFilters
    {
        return new ChannelReportFilters(
            $this->from,
            $this->to,
            match ($this->compare) {
                'same_period_last_year' => ChannelReportFilters::COMPARE_LAST_YEAR,
                'none' => ChannelReportFilters::COMPARE_NONE,
                default => ChannelReportFilters::COMPARE_PREVIOUS,
            },
            $orderTypes,
        );
    }

    private static function parseDate(string $value, string $field): CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new InvalidArgumentException("{$field} must be a date like 2026-11-01.");
        }

        return CarbonImmutable::parse($value)->startOfDay();
    }
}
