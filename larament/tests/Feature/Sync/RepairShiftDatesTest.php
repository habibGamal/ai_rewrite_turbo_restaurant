<?php

use App\Models\ExpenceType;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Shift;
use App\Models\SyncOutbox;
use App\Sync\Builders\OrderBuilder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Carbon::setTestNow('2026-10-30 15:00:00');
});

afterEach(fn () => Carbon::setTestNow());

function corruptedHistoricalShift(): Shift
{
    return Shift::factory()->create([
        'start_at' => '2026-10-30 13:42:10',
        'end_at' => '2026-08-02 05:34:37',
        'created_at' => '2026-08-01 12:55:49',
        'business_date' => '2026-10-30',
        'closed' => true,
    ]);
}

it('previews corrupted shifts without changing dates or queueing documents', function () {
    $shift = corruptedHistoricalShift();
    $this->artisan('platform:repair-shift-dates')->expectsOutputToContain('1')->assertSuccessful();
    expect($shift->fresh()->business_date->toDateString())->toBe('2026-10-30')
        ->and(SyncOutbox::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('repairs'))->toBe([]);
});

it('requires an explicit historical timestamp choice before applying repairs', function () {
    $shift = corruptedHistoricalShift();
    $this->artisan('platform:repair-shift-dates --apply')->assertFailed();
    expect($shift->fresh()->business_date->toDateString())->toBe('2026-10-30');
});

it('backs up and restores historical dates and queues new versions of affected documents', function () {
    $shift = corruptedHistoricalShift();
    $order = Order::factory()->create(['shift_id' => $shift->id]);
    $expense = Expense::factory()->create(['shift_id' => $shift->id, 'expence_type_id' => ExpenceType::factory()->create()->id]);
    $return = OrderReturn::create(['order_id' => $order->id, 'shift_id' => $shift->id, 'user_id' => $shift->user_id, 'total_refund' => 0, 'reverse_stock' => false]);
    pairPlatform();
    $old = SyncOutbox::create(['doc_type' => 'order', 'doc_uuid' => $order->uuid, 'op' => 'upsert', 'sent_at' => now()]);

    $this->artisan('platform:repair-shift-dates --apply --from-created-at')->assertSuccessful();

    $shift->refresh();
    expect($shift->start_at->format('Y-m-d H:i:s'))->toBe('2026-08-01 12:55:49')
        ->and($shift->business_date->toDateString())->toBe('2026-08-01')
        ->and($shift->created_at->format('Y-m-d H:i:s'))->toBe('2026-08-01 12:55:49')
        ->and(app(OrderBuilder::class)->build($order->uuid)['business_date'])->toBe('2026-08-01');
    foreach (['shift' => $shift->uuid, 'order' => $order->uuid, 'order_return' => $return->uuid, 'expense' => $expense->uuid] as $type => $uuid) {
        expect(SyncOutbox::where('doc_type', $type)->where('doc_uuid', $uuid)->where('id', '>', $old->id)->whereNull('sent_at')->exists())->toBeTrue();
    }
    expect(SyncOutbox::where('doc_type', 'day_summary')->orderBy('doc_key')->pluck('doc_key')->all())
        ->toBe(['2026-08-01', '2026-10-30']);
    $files = Storage::disk('local')->allFiles('repairs');
    expect($files)->toHaveCount(1);
    $backup = json_decode(Storage::disk('local')->get($files[0]), true, flags: JSON_THROW_ON_ERROR);
    expect($backup[0]['start_at'])->toBe('2026-10-30 13:42:10')
        ->and(Carbon::parse($backup[0]['business_date'])->toDateString())->toBe('2026-10-30');
    $count = SyncOutbox::count();
    $this->artisan('platform:repair-shift-dates --apply --from-created-at')->assertSuccessful();
    expect(SyncOutbox::count())->toBe($count);
});

it('preserves valid after-midnight business dates and ambiguous historical shifts', function () {
    $valid = Shift::factory()->create(['start_at' => '2026-08-02 01:00:00', 'end_at' => '2026-08-02 05:00:00', 'business_date' => '2026-08-01']);
    $unknown = corruptedHistoricalShift();
    $unknown->forceFill(['created_at' => null])->saveQuietly();
    $this->artisan('platform:repair-shift-dates --apply --from-created-at')->assertSuccessful();
    expect($valid->fresh()->business_date->toDateString())->toBe('2026-08-01')
        ->and($unknown->fresh()->business_date->toDateString())->toBe('2026-10-30');
});

it('does not modify dates if the audit backup cannot be saved', function () {
    $shift = corruptedHistoricalShift();
    Storage::shouldReceive('disk')->with('local')->andReturnSelf();
    Storage::shouldReceive('put')->andReturn(false);
    $this->artisan('platform:repair-shift-dates --apply --from-created-at')->assertFailed();
    expect($shift->fresh()->business_date->toDateString())->toBe('2026-10-30');
});

it('repairs an overwritten open shift when historical orders prove it started earlier', function () {
    $shift = Shift::factory()->create([
        'start_at' => '2026-10-30 13:42:11', 'end_at' => null,
        'created_at' => '2026-10-29 10:02:59', 'business_date' => '2026-10-30', 'closed' => false,
    ]);
    $order = Order::factory()->create(['shift_id' => $shift->id, 'created_at' => '2026-10-29 11:00:00']);
    $this->artisan('platform:repair-shift-dates --apply --from-created-at')->assertSuccessful();
    expect($shift->fresh()->start_at->format('Y-m-d H:i:s'))->toBe('2026-10-29 10:02:59')
        ->and($shift->fresh()->business_date->toDateString())->toBe('2026-10-29')
        ->and(app(OrderBuilder::class)->build($order->uuid)['business_date'])->toBe('2026-10-29');
});

it('preserves an open shift without historical orders proving timestamp damage', function () {
    $shift = Shift::factory()->create([
        'start_at' => '2026-10-30 13:42:11', 'end_at' => null,
        'created_at' => '2026-10-29 10:02:59', 'business_date' => '2026-10-30', 'closed' => false,
    ]);
    $this->artisan('platform:repair-shift-dates --apply --from-created-at')->assertSuccessful();
    expect($shift->fresh()->business_date->toDateString())->toBe('2026-10-30');
});
