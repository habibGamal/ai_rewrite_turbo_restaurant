<?php

use App\Sync\Payload;
use Carbon\Carbon;

it('formats money as 2-decimal strings without thousands separator', function () {
    expect(Payload::money(1234.5))->toBe('1234.50')
        ->and(Payload::money('400'))->toBe('400.00')
        ->and(Payload::money(0))->toBe('0.00')
        ->and(Payload::money(0.0))->toBe('0.00')
        ->and(Payload::money(-20.5))->toBe('-20.50')
        ->and(Payload::money(null))->toBeNull();
});

it('formats quantities as 3-decimal strings', function () {
    expect(Payload::qty(1.5))->toBe('1.500')
        ->and(Payload::qty('2'))->toBe('2.000')
        ->and(Payload::qty(0.125))->toBe('0.125')
        ->and(Payload::qty(0))->toBe('0.000')
        ->and(Payload::qty(null))->toBeNull();
});

it('formats timestamps in ISO-8601 with Cairo timezone offset', function () {
    pairPlatform();

    $time = Carbon::parse('2026-10-29 18:02:55', 'UTC');

    expect(Payload::ts($time))->toBe('2026-10-29T21:02:55+03:00')
        ->and(Payload::ts(null))->toBeNull();
});

it('formats dates as Y-m-d strings', function () {
    $date = Carbon::parse('2026-10-29 18:02:55');

    expect(Payload::date($date))->toBe('2026-10-29')
        ->and(Payload::date('2026-10-29 00:00:00'))->toBe('2026-10-29')
        ->and(Payload::date(null))->toBeNull();
});

it('hashes customer phone with the branch secret', function () {
    pairPlatform(); // branch_secret = 'test-secret'

    $phone = '01012345678';
    $expected = hash('sha256', $phone.'test-secret');

    expect(Payload::customerHash($phone))->toBe($expected)
        ->and(Payload::customerHash(null))->toBeNull()
        ->and(Payload::customerHash(''))->toBeNull();
});
