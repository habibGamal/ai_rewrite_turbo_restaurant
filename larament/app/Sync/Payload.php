<?php

namespace App\Sync;

use App\Models\PlatformConnection;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeInterface;

class Payload
{
    /**
     * Format a monetary value as a 2-decimal string (e.g. "400.00").
     */
    public static function money(float|string|int|null $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }

        return number_format((float) $v, 2, '.', '');
    }

    /**
     * Format a quantity as a 3-decimal string (e.g. "1.500").
     */
    public static function qty(float|string|int|null $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }

        return number_format((float) $v, 3, '.', '');
    }

    /**
     * Format a timestamp as an ISO-8601 string with branch timezone offset.
     */
    public static function ts(?CarbonInterface $t): ?string
    {
        if ($t === null) {
            return null;
        }

        $active = PlatformConnection::active();
        $timezone = $active ? (string) $active->timezone : 'Africa/Cairo';

        return Carbon::instance($t)->setTimezone($timezone)->toIso8601String();
    }

    /**
     * Format a date as a Y-m-d string.
     */
    public static function date(DateTimeInterface|string|null $d): ?string
    {
        if ($d === null) {
            return null;
        }

        if ($d instanceof DateTimeInterface) {
            return $d->format('Y-m-d');
        }

        $str = trim((string) $d);

        return $str === '' ? null : substr($str, 0, 10);
    }

    /**
     * Hash customer phone with the platform connection's branch secret.
     */
    public static function customerHash(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $phone = trim($phone);
        if ($phone === '') {
            return null;
        }

        $active = PlatformConnection::active();
        $secret = $active ? (string) $active->branch_secret : '';

        return hash('sha256', $phone.$secret);
    }
}
