<?php

use App\Models\Shift;
use App\Models\User;
use App\Sync\Builders\ShiftBuilder;
use Carbon\Carbon;
use Illuminate\Support\Str;

it('builds a shift snapshot', function () {
    pairPlatform();

    $user = User::factory()->create();

    $shift = Shift::factory()->create([
        'user_id' => $user->id,
        'business_date' => '2026-10-29',
        'start_at' => Carbon::parse('2026-10-29 08:00:00', 'Africa/Cairo'),
        'end_at' => Carbon::parse('2026-10-29 16:00:00', 'Africa/Cairo'),
        'start_cash' => 500.00,
        'end_cash' => 1500.00,
        'real_cash' => 1480.00,
        'losses_amount' => 20.00,
        'has_deficit' => true,
        'closed' => true,
        'money_edited_at' => null,
        'money_edited_count' => 0,
    ]);

    $data = app(ShiftBuilder::class)->build($shift->uuid);

    expect($data)->toEqual([
        'business_date' => '2026-10-29',
        'user_uuid' => $user->uuid,
        'start_at' => '2026-10-29T08:00:00+03:00',
        'end_at' => '2026-10-29T16:00:00+03:00',
        'start_cash' => '500.00',
        'end_cash' => '1500.00',
        'real_cash' => '1480.00',
        'losses_amount' => '20.00',
        'has_deficit' => true,
        'closed' => true,
        'money_edited_at' => null,
        'money_edited_count' => 0,
        'terminal_sessions' => [],
    ]);
});

it('returns null for an unknown shift uuid', function () {
    expect(app(ShiftBuilder::class)->build((string) Str::uuid()))->toBeNull();
});
