<?php

use App\Enums\KitchenItemStatus;
use App\Enums\KitchenTicketStatus;
use App\Models\KitchenTicket;
use App\Models\KitchenTicketItem;
use App\Models\KitchenTicketRound;
use Carbon\Carbon;

it('preserves sent_at on kitchen ticket items when row is updated', function () {
    Carbon::setTestNow('2026-11-14 12:00:00');

    $item = KitchenTicketItem::factory()->create([
        'status' => KitchenItemStatus::PENDING,
        'sent_at' => now(),
    ]);

    $originalSentAt = $item->sent_at->toDateTimeString();

    // Advance time by 15 minutes
    Carbon::setTestNow('2026-11-14 12:15:00');

    // Update item status and done_at
    $item->update([
        'status' => KitchenItemStatus::DONE,
        'done_at' => now(),
        'notes' => 'Updated notes',
    ]);

    $item->refresh();

    expect($item->sent_at->toDateTimeString())->toBe($originalSentAt)
        ->and($item->done_at->toDateTimeString())->toBe('2026-11-14 12:15:00');

    // Preparation duration in minutes should be exactly 15 minutes
    $prepMinutes = (int) $item->sent_at->diffInMinutes($item->done_at);
    expect($prepMinutes)->toBe(15);
});

it('preserves first_sent_at on kitchen tickets when row is updated', function () {
    Carbon::setTestNow('2026-11-14 12:00:00');

    $ticket = KitchenTicket::factory()->create([
        'status' => KitchenTicketStatus::ACTIVE,
        'first_sent_at' => now(),
    ]);

    $originalFirstSentAt = $ticket->first_sent_at->toDateTimeString();

    Carbon::setTestNow('2026-11-14 12:30:00');

    $ticket->update([
        'status' => KitchenTicketStatus::DONE,
        'done_at' => now(),
        'is_rush' => true,
    ]);

    $ticket->refresh();

    expect($ticket->first_sent_at->toDateTimeString())->toBe($originalFirstSentAt)
        ->and($ticket->done_at->toDateTimeString())->toBe('2026-11-14 12:30:00');
});

it('preserves started_at on kitchen ticket rounds when row is updated', function () {
    Carbon::setTestNow('2026-11-14 12:00:00');

    $round = KitchenTicketRound::factory()->create([
        'started_at' => now(),
        'ended_at' => null,
    ]);

    $originalStartedAt = $round->started_at->toDateTimeString();

    Carbon::setTestNow('2026-11-14 12:20:00');

    $round->update([
        'ended_at' => now(),
    ]);

    $round->refresh();

    expect($round->started_at->toDateTimeString())->toBe($originalStartedAt)
        ->and($round->ended_at->toDateTimeString())->toBe('2026-11-14 12:20:00');
});
