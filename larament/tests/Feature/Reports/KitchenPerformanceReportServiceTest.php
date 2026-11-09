<?php

use App\Enums\KitchenItemStatus;
use App\Enums\KitchenTicketStatus;
use App\Models\KitchenStation;
use App\Models\KitchenTicket;
use App\Models\KitchenTicketItem;
use App\Models\KitchenTicketRound;
use App\Models\Order;
use App\Services\KitchenPerformanceReportService;
use Carbon\Carbon;

/**
 * @param  array<string, mixed>  $attributes
 */
function kdsReportTicket(array $attributes = []): KitchenTicket
{
    $order = Order::factory()->create([
        'type' => $attributes['type'] ?? 'dine_in',
        'order_number' => $attributes['order_number'] ?? fake()->unique()->numberBetween(1, 999999),
    ]);

    unset($attributes['type'], $attributes['order_number']);

    return KitchenTicket::factory()->create([
        'order_id' => $order->id,
        'status' => KitchenTicketStatus::DONE,
        'first_sent_at' => now()->startOfDay()->addHours(10),
        'done_at' => now()->startOfDay()->addHours(10),
        ...$attributes,
    ]);
}

function kdsReportRound(KitchenTicket $ticket, Carbon $start, ?Carbon $end, int $number = 1): KitchenTicketRound
{
    return KitchenTicketRound::factory()->create([
        'kitchen_ticket_id' => $ticket->id,
        'number' => $number,
        'started_at' => $start,
        'ended_at' => $end,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function kdsReportItem(KitchenTicket $ticket, KitchenTicketRound $round, array $attributes = []): KitchenTicketItem
{
    return KitchenTicketItem::factory()->create([
        'kitchen_ticket_id' => $ticket->id,
        'kitchen_ticket_round_id' => $round->id,
        'product_id' => null,
        'kitchen_station_id' => null,
        'product_name' => 'منتج',
        'kind' => 'original',
        'target_minutes' => 10,
        'sent_at' => $round->started_at,
        'status' => KitchenItemStatus::PENDING,
        ...$attributes,
    ]);
}

it('sums only closed rounds and ignores idle gaps between them', function () {
    $base = now()->startOfDay()->addHours(10);

    $ticket = kdsReportTicket(['first_sent_at' => $base]);

    $firstRound = kdsReportRound($ticket, $base, $base->copy()->addMinutes(6), 1);
    kdsReportItem($ticket, $firstRound, ['target_minutes' => 10]);

    // A dessert added 40 minutes later: the 34 minute gap must not count.
    $secondRound = kdsReportRound($ticket, $base->copy()->addMinutes(40), $base->copy()->addMinutes(50), 2);
    kdsReportItem($ticket, $secondRound, ['target_minutes' => 10]);

    $summary = app(KitchenPerformanceReportService::class)
        ->getSummary($base->toDateString(), $base->toDateString());

    expect($summary['tickets_count'])->toBe(1)
        ->and($summary['avg_kitchen_seconds'])->toBe(960.0);
});

it('detects late rounds using the largest target of non-voided items only', function () {
    $base = now()->startOfDay()->addHours(10);

    $ticket = kdsReportTicket(['first_sent_at' => $base]);

    // 12 minutes against a 10 minute target: late. A voided item with a much
    // larger target must not raise the threshold.
    $lateRound = kdsReportRound($ticket, $base, $base->copy()->addMinutes(12), 1);
    kdsReportItem($ticket, $lateRound, ['target_minutes' => 10]);
    kdsReportItem($ticket, $lateRound, [
        'target_minutes' => 60,
        'status' => KitchenItemStatus::VOIDED,
        'voided_at' => $base->copy()->addMinutes(12),
    ]);

    // 5 minutes against 10: not late.
    $onTimeRound = kdsReportRound($ticket, $base->copy()->addMinutes(30), $base->copy()->addMinutes(35), 2);
    kdsReportItem($ticket, $onTimeRound, ['target_minutes' => 10]);

    $summary = app(KitchenPerformanceReportService::class)
        ->getSummary($base->toDateString(), $base->toDateString());

    expect($summary['late_rounds_percent'])->toBe(50.0);
});

it('excludes auto-finished items from item prep time', function () {
    $base = now()->startOfDay()->addHours(10);

    $ticket = kdsReportTicket(['first_sent_at' => $base]);
    $round = kdsReportRound($ticket, $base, $base->copy()->addMinutes(10));

    kdsReportItem($ticket, $round, [
        'product_name' => 'شاورما',
        'status' => KitchenItemStatus::DONE,
        'sent_at' => $base,
        'done_at' => $base->copy()->addMinutes(6),
        'auto_finished' => false,
    ]);

    // Auto-finished items inherit the ticket's finish time and are not measured.
    kdsReportItem($ticket, $round, [
        'product_name' => 'بطاطس',
        'status' => KitchenItemStatus::DONE,
        'sent_at' => $base,
        'done_at' => $base->copy()->addMinutes(10),
        'auto_finished' => true,
    ]);

    $service = app(KitchenPerformanceReportService::class);

    $summary = $service->getSummary($base->toDateString(), $base->toDateString());
    $products = $service->getProductPerformance($base->toDateString(), $base->toDateString());

    expect($summary['avg_item_prep_seconds'])->toBe(360.0)
        ->and($products)->toHaveCount(1)
        ->and($products[0]['product_name'])->toBe('شاورما')
        ->and($products[0]['avg_prep_seconds'])->toBe(360.0);
});

it('counts items voided after done as waste candidates', function () {
    $base = now()->startOfDay()->addHours(10);

    $ticket = kdsReportTicket(['first_sent_at' => $base]);
    $round = kdsReportRound($ticket, $base, $base->copy()->addMinutes(8));

    kdsReportItem($ticket, $round, [
        'product_name' => 'كفتة',
        'status' => KitchenItemStatus::VOIDED,
        'voided_after_done' => true,
        'voided_at' => $base->copy()->addMinutes(8),
    ]);

    kdsReportItem($ticket, $round, [
        'product_name' => 'سلطة',
        'status' => KitchenItemStatus::VOIDED,
        'voided_after_done' => false,
        'voided_at' => $base->copy()->addMinutes(8),
    ]);

    $service = app(KitchenPerformanceReportService::class);

    $summary = $service->getSummary($base->toDateString(), $base->toDateString());
    $waste = $service->getWasteCandidates($base->toDateString(), $base->toDateString());

    expect($summary['waste_candidates_count'])->toBe(1)
        ->and($waste)->toHaveCount(1)
        ->and($waste[0]['product_name'])->toBe('كفتة')
        ->and($waste[0]['station_name'])->toBe('عام');
});

it('excludes cancelled tickets from time metrics but keeps their waste candidates', function () {
    $base = now()->startOfDay()->addHours(10);

    $cancelled = kdsReportTicket([
        'first_sent_at' => $base,
        'status' => KitchenTicketStatus::CANCELLED,
        'cancelled_at' => $base->copy()->addMinutes(20),
    ]);
    $cancelledRound = kdsReportRound($cancelled, $base, $base->copy()->addMinutes(20));
    kdsReportItem($cancelled, $cancelledRound, [
        'status' => KitchenItemStatus::DONE,
        'sent_at' => $base,
        'done_at' => $base->copy()->addMinutes(20),
    ]);
    kdsReportItem($cancelled, $cancelledRound, [
        'status' => KitchenItemStatus::VOIDED,
        'voided_after_done' => true,
        'voided_at' => $base->copy()->addMinutes(20),
    ]);

    $active = kdsReportTicket(['first_sent_at' => $base]);
    $activeRound = kdsReportRound($active, $base, $base->copy()->addMinutes(5));
    kdsReportItem($active, $activeRound, [
        'status' => KitchenItemStatus::DONE,
        'sent_at' => $base,
        'done_at' => $base->copy()->addMinutes(5),
    ]);

    $service = app(KitchenPerformanceReportService::class);
    $summary = $service->getSummary($base->toDateString(), $base->toDateString());

    expect($summary['tickets_count'])->toBe(1)
        ->and($summary['avg_kitchen_seconds'])->toBe(300.0)
        ->and($summary['avg_item_prep_seconds'])->toBe(300.0)
        ->and($summary['waste_candidates_count'])->toBe(1);
});

it('groups item performance by station including a general bucket for null stations', function () {
    $base = now()->startOfDay()->addHours(10);

    $grill = KitchenStation::factory()->create(['name' => 'المشويات']);
    $cold = KitchenStation::factory()->create(['name' => 'البارد']);

    $ticket = kdsReportTicket(['first_sent_at' => $base]);
    $round = kdsReportRound($ticket, $base, $base->copy()->addMinutes(10));

    kdsReportItem($ticket, $round, [
        'product_name' => 'كباب',
        'kitchen_station_id' => $grill->id,
        'status' => KitchenItemStatus::DONE,
        'sent_at' => $base,
        'done_at' => $base->copy()->addMinutes(5),
    ]);

    kdsReportItem($ticket, $round, [
        'product_name' => 'سلطة',
        'kitchen_station_id' => $cold->id,
        'status' => KitchenItemStatus::DONE,
        'sent_at' => $base,
        'done_at' => $base->copy()->addMinutes(3),
    ]);

    kdsReportItem($ticket, $round, [
        'product_name' => 'خبز',
        'kitchen_station_id' => null,
        'status' => KitchenItemStatus::DONE,
        'sent_at' => $base,
        'done_at' => $base->copy()->addMinutes(2),
    ]);

    $stations = collect(app(KitchenPerformanceReportService::class)
        ->getStationPerformance($base->toDateString(), $base->toDateString()))
        ->keyBy('station_name');

    expect($stations)->toHaveCount(3)
        ->and($stations['المشويات']['avg_prep_seconds'])->toBe(300.0)
        ->and($stations['البارد']['avg_prep_seconds'])->toBe(180.0)
        ->and($stations['عام']['avg_prep_seconds'])->toBe(120.0)
        ->and($stations['عام']['station_id'])->toBeNull()
        ->and($stations['المشويات']['count'])->toBe(1);
});

it('groups kitchen time by order type with enum labels', function () {
    $base = now()->startOfDay()->addHours(10);

    foreach ([6, 10] as $minutes) {
        $ticket = kdsReportTicket(['first_sent_at' => $base, 'type' => 'dine_in']);
        $round = kdsReportRound($ticket, $base, $base->copy()->addMinutes($minutes));
        kdsReportItem($ticket, $round);
    }

    $takeaway = kdsReportTicket(['first_sent_at' => $base, 'type' => 'takeaway']);
    $takeawayRound = kdsReportRound($takeaway, $base, $base->copy()->addMinutes(20));
    kdsReportItem($takeaway, $takeawayRound);

    $rows = collect(app(KitchenPerformanceReportService::class)
        ->getOrderTypePerformance($base->toDateString(), $base->toDateString()))
        ->keyBy('type');

    expect($rows['dine_in']['label'])->toBe('صالة')
        ->and($rows['dine_in']['count'])->toBe(2)
        ->and($rows['dine_in']['avg_kitchen_seconds'])->toBe(480.0)
        ->and($rows['takeaway']['count'])->toBe(1)
        ->and($rows['takeaway']['avg_kitchen_seconds'])->toBe(1200.0);
});

it('sorts products slowest first', function () {
    $base = now()->startOfDay()->addHours(10);

    $ticket = kdsReportTicket(['first_sent_at' => $base]);
    $round = kdsReportRound($ticket, $base, $base->copy()->addMinutes(15));

    kdsReportItem($ticket, $round, [
        'product_name' => 'سريع',
        'status' => KitchenItemStatus::DONE,
        'sent_at' => $base,
        'done_at' => $base->copy()->addMinutes(2),
    ]);

    kdsReportItem($ticket, $round, [
        'product_name' => 'بطيء',
        'status' => KitchenItemStatus::DONE,
        'sent_at' => $base,
        'done_at' => $base->copy()->addMinutes(10),
    ]);

    $products = app(KitchenPerformanceReportService::class)
        ->getProductPerformance($base->toDateString(), $base->toDateString());

    expect($products[0]['product_name'])->toBe('بطيء')
        ->and($products[0]['avg_prep_seconds'])->toBe(600.0)
        ->and($products[0]['late_items_percent'])->toBe(0.0)
        ->and($products[1]['product_name'])->toBe('سريع');
});

it('applies the optional station filter to tickets, items and waste', function () {
    $base = now()->startOfDay()->addHours(10);

    $grill = KitchenStation::factory()->create(['name' => 'المشويات']);
    $cold = KitchenStation::factory()->create(['name' => 'البارد']);

    $grillTicket = kdsReportTicket(['first_sent_at' => $base]);
    $grillRound = kdsReportRound($grillTicket, $base, $base->copy()->addMinutes(6));
    kdsReportItem($grillTicket, $grillRound, [
        'kitchen_station_id' => $grill->id,
        'status' => KitchenItemStatus::DONE,
        'sent_at' => $base,
        'done_at' => $base->copy()->addMinutes(6),
    ]);

    $coldTicket = kdsReportTicket(['first_sent_at' => $base]);
    $coldRound = kdsReportRound($coldTicket, $base, $base->copy()->addMinutes(12));
    kdsReportItem($coldTicket, $coldRound, [
        'kitchen_station_id' => $cold->id,
        'status' => KitchenItemStatus::VOIDED,
        'voided_after_done' => true,
        'voided_at' => $base->copy()->addMinutes(12),
    ]);

    $service = app(KitchenPerformanceReportService::class);
    $summary = $service->getSummary($base->toDateString(), $base->toDateString(), $grill->id);

    expect($summary['tickets_count'])->toBe(1)
        ->and($summary['avg_kitchen_seconds'])->toBe(360.0)
        ->and($summary['waste_candidates_count'])->toBe(0)
        ->and($service->getStationPerformance($base->toDateString(), $base->toDateString(), $grill->id))
        ->toHaveCount(1);
});

it('formats durations as mm:ss', function () {
    expect(KitchenPerformanceReportService::formatDuration(0))->toBe('00:00')
        ->and(KitchenPerformanceReportService::formatDuration(65))->toBe('01:05')
        ->and(KitchenPerformanceReportService::formatDuration(600))->toBe('10:00');
});
