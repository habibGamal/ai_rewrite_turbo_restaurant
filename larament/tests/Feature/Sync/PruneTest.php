<?php

use App\Models\PlatformConnection;
use App\Models\SyncBatch;
use App\Models\SyncOutbox;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    PlatformConnection::flushCache();
    Http::preventStrayRequests();
});

it('prunes sent rows older than 30 days only', function () {
    $now = now();

    // Row 1: Sent 31 days ago (should be pruned)
    $oldSent = SyncOutbox::create([
        'doc_type' => 'order',
        'doc_uuid' => (string) Str::uuid(),
        'occurred_at' => $now->copy()->subDays(35),
        'sent_at' => $now->copy()->subDays(31),
    ]);

    // Row 2: Sent 10 days ago (should NOT be pruned)
    $recentSent = SyncOutbox::create([
        'doc_type' => 'order',
        'doc_uuid' => (string) Str::uuid(),
        'occurred_at' => $now->copy()->subDays(12),
        'sent_at' => $now->copy()->subDays(10),
    ]);

    // Row 3: Pending, occurred 40 days ago (should NOT be pruned)
    $oldPending = SyncOutbox::create([
        'doc_type' => 'order',
        'doc_uuid' => (string) Str::uuid(),
        'occurred_at' => $now->copy()->subDays(40),
        'sent_at' => null,
        'failed_at' => null,
    ]);

    // Row 4: Failed, occurred 40 days ago, failed 35 days ago (should NOT be pruned)
    $oldFailed = SyncOutbox::create([
        'doc_type' => 'order',
        'doc_uuid' => (string) Str::uuid(),
        'occurred_at' => $now->copy()->subDays(40),
        'failed_at' => $now->copy()->subDays(35),
    ]);

    $this->artisan('platform:prune')->assertSuccessful();

    expect(SyncOutbox::find($oldSent->id))->toBeNull()
        ->and(SyncOutbox::find($recentSent->id))->not->toBeNull()
        ->and(SyncOutbox::find($oldPending->id))->not->toBeNull()
        ->and(SyncOutbox::find($oldFailed->id))->not->toBeNull();
});

it('prunes batches older than 30 days', function () {
    $now = now();

    // Batch created 35 days ago (should be pruned)
    $oldBatch = SyncBatch::create([
        'uuid' => (string) Str::uuid(),
        'seq_from' => 1,
        'seq_to' => 10,
        'docs_count' => 10,
        'status' => 'sent',
    ]);
    $oldBatch->created_at = $now->copy()->subDays(35);
    $oldBatch->save();

    // Batch created 10 days ago (should NOT be pruned)
    $recentBatch = SyncBatch::create([
        'uuid' => (string) Str::uuid(),
        'seq_from' => 11,
        'seq_to' => 20,
        'docs_count' => 10,
        'status' => 'sent',
    ]);
    $recentBatch->created_at = $now->copy()->subDays(10);
    $recentBatch->save();

    $this->artisan('platform:prune')->assertSuccessful();

    expect(SyncBatch::find($oldBatch->id))->toBeNull()
        ->and(SyncBatch::find($recentBatch->id))->not->toBeNull();
});

it('retains old pending batches so offline work remains retryable', function () {
    $batch = SyncBatch::create([
        'uuid' => (string) Str::uuid(), 'seq_from' => 1, 'seq_to' => 1,
        'docs_count' => 1, 'status' => 'pending',
    ]);
    $batch->created_at = now()->subDays(40);
    $batch->save();
    $row = SyncOutbox::create([
        'doc_type' => 'order', 'doc_uuid' => (string) Str::uuid(),
        'batch_id' => $batch->id, 'occurred_at' => now()->subDays(40),
    ]);
    $this->artisan('platform:prune')->assertSuccessful();
    expect($batch->fresh())->not->toBeNull()
        ->and($row->fresh()->batch_id)->toBe($batch->id);
});
