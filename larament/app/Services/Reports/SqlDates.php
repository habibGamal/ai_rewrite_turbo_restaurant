<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\DB;

/**
 * Date parts in SQL for MySQL (production) and SQLite (tests), so a report
 * can group by day, hour or weekday on either driver.
 */
final class SqlDates
{
    public static function day(string $column): string
    {
        return self::sqlite() ? "date({$column})" : "DATE({$column})";
    }

    public static function hour(string $column): string
    {
        return self::sqlite() ? "CAST(strftime('%H', {$column}) AS INTEGER)" : "HOUR({$column})";
    }

    /** 1 = Sunday … 7 = Saturday on both drivers (MySQL DAYOFWEEK). */
    public static function dayOfWeek(string $column): string
    {
        return self::sqlite() ? "(CAST(strftime('%w', {$column}) AS INTEGER) + 1)" : "DAYOFWEEK({$column})";
    }

    /**
     * The first day of the bucket a timestamp falls in, as Y-m-d: the day
     * itself, its week (weeks start on Saturday) or its month.
     */
    public static function bucket(string $column, string $granularity): string
    {
        return match ($granularity) {
            'week' => self::sqlite()
                ? "date({$column}, '-' || ((CAST(strftime('%w', {$column}) AS INTEGER) + 1) % 7) || ' days')"
                : "DATE_SUB(DATE({$column}), INTERVAL (DAYOFWEEK({$column}) % 7) DAY)",
            'month' => self::sqlite() ? "strftime('%Y-%m-01', {$column})" : "DATE_FORMAT({$column}, '%Y-%m-01')",
            default => self::day($column),
        };
    }

    /** SQL that joins two expressions with a '|' between them. */
    public static function pair(string $first, string $second): string
    {
        return self::sqlite() ? "({$first} || '|' || {$second})" : "CONCAT({$first}, '|', {$second})";
    }

    private static function sqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }
}
