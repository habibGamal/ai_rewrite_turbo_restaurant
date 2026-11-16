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

    private static function sqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }
}
