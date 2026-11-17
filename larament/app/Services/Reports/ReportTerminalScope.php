<?php

namespace App\Services\Reports;

use App\Models\Terminal;
use App\Models\TerminalSession;
use App\Services\FeatureService;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The sales point tab picked on the current-shift and shift-period reports
 * («الكل» or one sales point). Scoped per request: each report widget sets
 * it from the page filters before computing, and the report queries narrow
 * themselves through it. Null = all sales points (the reports as before).
 *
 * Orders follow `orders.terminal_id`; money rows (payments, expenses,
 * revenues, returns, external collections and settlements) follow the
 * drawer session they were taken in; opening cash is the sales point's
 * drawer sessions' opening cash.
 */
class ReportTerminalScope
{
    public const FILTER = 'terminal';

    public const ALL = 'all';

    private ?int $terminalId = null;

    /**
     * @param  array<string, mixed>|null  $filters
     */
    public function setFromFilters(?array $filters): void
    {
        $value = $filters[self::FILTER] ?? null;

        $this->terminalId = $value === null || $value === '' || $value === self::ALL || ! app(FeatureService::class)->multiTerminalEnabled()
            ? null
            : (int) $value;
    }

    public function set(?int $terminalId): void
    {
        $this->terminalId = $terminalId;
    }

    public function id(): ?int
    {
        return $this->terminalId;
    }

    public function active(): bool
    {
        return $this->terminalId !== null;
    }

    /**
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    public function orders(Builder $query, string $column = 'orders.terminal_id'): Builder
    {
        if ($this->terminalId !== null) {
            $query->where($column, $this->terminalId);
        }

        return $query;
    }

    /**
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    public function money(Builder $query, string $column = 'terminal_session_id'): Builder
    {
        if ($this->terminalId !== null) {
            $query->whereIn($column, $this->sessionIds());
        }

        return $query;
    }

    public function sessionIds(): QueryBuilder
    {
        return DB::table('terminal_sessions')->select('terminal_sessions.id')->where('terminal_sessions.terminal_id', $this->terminalId);
    }

    /**
     * Opening cash of the picked sales point's drawer sessions in these shifts.
     *
     * @param  QueryBuilder|array<int|string>  $shiftIds
     */
    public function openingCash(QueryBuilder|array $shiftIds): float
    {
        return (float) TerminalSession::query()
            ->where('terminal_id', $this->terminalId)
            ->whereIn('shift_id', $shiftIds instanceof QueryBuilder ? clone $shiftIds : $shiftIds)
            ->sum('opening_cash');
    }

    /**
     * Tabs for the reports: «الكل» plus each sales point that had a drawer
     * session in these shifts. Empty while there is at most one sales point.
     *
     * @param  QueryBuilder|array<int|string>  $shiftIds
     * @return array<string, string>
     */
    public static function tabs(QueryBuilder|array $shiftIds): array
    {
        if (! app(FeatureService::class)->multiTerminalEnabled()) {
            return [];
        }

        $terminals = Terminal::query()
            ->whereIn('id', TerminalSession::query()
                ->select('terminal_id')
                ->whereIn('shift_id', $shiftIds instanceof QueryBuilder ? clone $shiftIds : $shiftIds))
            ->orderBy('sort')
            ->orderBy('id')
            ->pluck('name', 'id');

        if ($terminals->count() < 2) {
            return [];
        }

        return [self::ALL => 'الكل'] + $terminals->mapWithKeys(fn (string $name, int $id): array => [(string) $id => $name])->all();
    }
}
