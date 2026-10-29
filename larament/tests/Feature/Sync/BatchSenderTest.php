<?php

use App\Models\Category;
use App\Models\PlatformConnection;
use App\Models\SyncBatch;
use App\Models\SyncOutbox;
use App\Sync\BatchSender;
use Carbon\Carbon;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

it('sends due rows as one gzip batch and marks them sent', function () {
    $connection = pairPlatform();

    $cat1 = Category::factory()->create(['name' => 'Category 1']);
    $cat2 = Category::factory()->create(['name' => 'Category 2']);

    Http::fake([
        '*/api/v1/ingest/batches' => Http::response([
            'batch_uuid' => 'batch-uuid',
            'accepted' => [$cat1->uuid, $cat2->uuid],
            'rejected' => [],
        ], 202),
    ]);

    $result = app(BatchSender::class)->run();

    expect($result->batches)->toBe(1)
        ->and($result->sent)->toBe(2)
        ->and($result->rejected)->toBe(0)
        ->and($result->failed)->toBe(0);

    Http::assertSent(function (Request $request) use ($connection, $cat1, $cat2) {
        expect($request->header('Authorization'))->toEqual(['Bearer test-token'])
            ->and($request->header('Content-Type'))->toEqual(['application/json'])
            ->and($request->header('Content-Encoding'))->toEqual(['gzip'])
            ->and($request->header('X-Schema-Version'))->toEqual(['1'])
            ->and($request->header('X-Pos-Version'))->toEqual([(string) config('app.version')])
            ->and($request->hasHeader('Idempotency-Key'))->toBeTrue();

        $rawBody = $request->body();
        $decompressed = gzdecode($rawBody);
        expect($decompressed)->not->toBeFalse();

        $payload = json_decode((string) $decompressed, true);
        expect($payload)->toHaveKeys(['schema_version', 'batch_uuid', 'branch_uuid', 'pos_version', 'sent_at', 'seq_from', 'seq_to', 'documents'])
            ->and($payload['schema_version'])->toBe(1)
            ->and($payload['branch_uuid'])->toBe($connection->branch_uuid)
            ->and($payload['pos_version'])->toBe((string) config('app.version'))
            ->and($payload['documents'])->toHaveCount(2);

        $docUuids = collect($payload['documents'])->pluck('uuid')->all();
        expect($docUuids)->toContain($cat1->uuid, $cat2->uuid);

        return true;
    });

    expect(SyncOutbox::whereNotNull('sent_at')->count())->toBe(2)
        ->and(SyncBatch::where('status', 'sent')->count())->toBe(1);
});

it('coalesces many rows of the same document into one with the highest version', function () {
    pairPlatform();

    $category = Category::factory()->create(['name' => 'Version 1']);
    $category->update(['name' => 'Version 2']);
    $category->update(['name' => 'Version 3']);

    $rows = SyncOutbox::where('doc_uuid', $category->uuid)->orderBy('id')->get();
    expect($rows)->toHaveCount(3);
    $highestId = $rows->max('id');

    Http::fake([
        '*/api/v1/ingest/batches' => Http::response([
            'accepted' => [$category->uuid],
            'rejected' => [],
        ], 202),
    ]);

    $result = app(BatchSender::class)->run();

    expect($result->batches)->toBe(1)
        ->and($result->sent)->toBe(1);

    Http::assertSent(function (Request $request) use ($category, $highestId) {
        $decompressed = gzdecode($request->body());
        $payload = json_decode((string) $decompressed, true);

        expect($payload['documents'])->toHaveCount(1)
            ->and($payload['documents'][0]['uuid'])->toBe($category->uuid)
            ->and($payload['documents'][0]['version'])->toBe((int) $highestId)
            ->and($payload['documents'][0]['data']['name'])->toBe('Version 3');

        return true;
    });

    expect(SyncOutbox::where('doc_uuid', $category->uuid)->whereNull('sent_at')->count())->toBe(0)
        ->and(SyncOutbox::where('doc_uuid', $category->uuid)->whereNotNull('sent_at')->count())->toBe(3);
});

it('reuses the same batch uuid after a 500', function () {
    pairPlatform();
    Carbon::setTestNow('2026-11-01 10:00:00');

    $category = Category::factory()->create(['name' => 'Cat']);

    // Sequence: 1st call fails with 500, 2nd call succeeds with 202
    Http::fake([
        '*/api/v1/ingest/batches' => Http::sequence()
            ->push(['error' => 'Server Error'], 500)
            ->push(['accepted' => [$category->uuid], 'rejected' => []], 202),
    ]);

    $sender = app(BatchSender::class);
    $result1 = $sender->run();

    expect($result1->batches)->toBe(0)
        ->and($result1->error)->toContain('500');

    $batch = SyncBatch::where('status', 'pending')->first();
    expect($batch)->not->toBeNull();
    $batchUuid = $batch->uuid;

    $outboxRow = SyncOutbox::where('doc_uuid', $category->uuid)->first();
    expect($outboxRow->attempts)->toBe(1)
        ->and($outboxRow->next_attempt_at->toDateTimeString())->toBe('2026-11-01 10:01:00')
        ->and($outboxRow->sent_at)->toBeNull()
        ->and($outboxRow->batch_id)->toBe($batch->id);

    // Advance time past the 1-minute backoff
    Carbon::setTestNow('2026-11-01 10:01:00');

    $result2 = $sender->run();

    expect($result2->batches)->toBe(1)
        ->and($result2->sent)->toBe(1);

    // Both calls must have sent the exact same Idempotency-Key
    $recorded = Http::recorded();
    expect($recorded)->toHaveCount(2);

    $key1 = $recorded[0][0]->header('Idempotency-Key')[0];
    $key2 = $recorded[1][0]->header('Idempotency-Key')[0];

    expect($key1)->toBe($batchUuid)
        ->and($key2)->toBe($batchUuid);

    expect($batch->fresh()->status)->toBe('sent')
        ->and($outboxRow->fresh()->sent_at)->not->toBeNull();
});

it('treats 409 as success', function () {
    $connection = pairPlatform();

    $category = Category::factory()->create(['name' => 'Cat 409']);

    Http::fake([
        '*/api/v1/ingest/batches' => Http::response([
            'batch_uuid' => 'existing-batch',
            'accepted' => [$category->uuid],
            'rejected' => [],
        ], 409),
    ]);

    $result = app(BatchSender::class)->run();

    expect($result->batches)->toBe(1)
        ->and($result->sent)->toBe(1)
        ->and(SyncOutbox::where('doc_uuid', $category->uuid)->value('sent_at'))->not->toBeNull()
        ->and(SyncBatch::first()->status)->toBe('sent')
        ->and($connection->fresh()->last_success_at)->not->toBeNull();
});

it('marks only rejected documents for retry with backoff', function () {
    pairPlatform();
    Carbon::setTestNow('2026-11-01 10:00:00');

    $cat1 = Category::factory()->create(['name' => 'Accepted Cat']);
    $cat2 = Category::factory()->create(['name' => 'Rejected Cat']);

    Http::fake([
        '*/api/v1/ingest/batches' => Http::response([
            'accepted' => [$cat1->uuid],
            'rejected' => [
                [
                    'uuid' => $cat2->uuid,
                    'type' => 'category',
                    'reason' => 'Invalid category format',
                ],
            ],
        ], 202),
    ]);

    $result = app(BatchSender::class)->run();

    expect($result->batches)->toBe(1)
        ->and($result->sent)->toBe(1)
        ->and($result->rejected)->toBe(1);

    $row1 = SyncOutbox::where('doc_uuid', $cat1->uuid)->first();
    expect($row1->sent_at)->not->toBeNull()
        ->and($row1->batch_id)->not->toBeNull()
        ->and($row1->last_error)->toBeNull();

    $row2 = SyncOutbox::where('doc_uuid', $cat2->uuid)->first();
    expect($row2->sent_at)->toBeNull()
        ->and($row2->batch_id)->toBeNull() // Released
        ->and($row2->attempts)->toBe(1)
        ->and($row2->last_error)->toBe('Invalid category format')
        ->and($row2->next_attempt_at->toDateTimeString())->toBe('2026-11-01 10:01:00');

    expect(SyncBatch::first()->status)->toBe('partial');
});

it('moves a row to failed after 10 attempts', function () {
    pairPlatform();
    Carbon::setTestNow('2026-11-01 10:00:00');

    $category = Category::factory()->create(['name' => 'Cat 10 attempts']);
    SyncOutbox::where('doc_uuid', $category->uuid)->update(['attempts' => 9]);

    Http::fake([
        '*/api/v1/ingest/batches' => Http::response([
            'accepted' => [],
            'rejected' => [
                [
                    'uuid' => $category->uuid,
                    'type' => 'category',
                    'reason' => 'Fatal rejection',
                ],
            ],
        ], 202),
    ]);

    $result = app(BatchSender::class)->run();

    expect($result->failed)->toBe(1);

    $row = SyncOutbox::where('doc_uuid', $category->uuid)->first();
    expect($row->attempts)->toBe(10)
        ->and($row->failed_at)->not->toBeNull()
        ->and($row->failed_at->toDateTimeString())->toBe('2026-11-01 10:00:00')
        ->and($row->batch_id)->toBeNull()
        ->and($row->sent_at)->toBeNull();
});

it('stops and flags the connection revoked on 401', function () {
    $connection = pairPlatform();

    $category = Category::factory()->create(['name' => 'Cat 401']);

    Http::fake([
        '*/api/v1/ingest/batches' => Http::response(['error' => 'Unauthorized'], 401),
    ]);

    $result = app(BatchSender::class)->run();

    expect($result->batches)->toBe(0)
        ->and($result->error)->toBe('token rejected');

    PlatformConnection::flushCache();
    $connection->refresh();

    expect($connection->status)->toBe('revoked')
        ->and($connection->last_error)->toBe('token rejected')
        ->and(SyncOutbox::where('doc_uuid', $category->uuid)->value('sent_at'))->toBeNull();
});

it('sends a delete when the record was hard-deleted before sending', function () {
    pairPlatform();

    $category = Category::factory()->create(['name' => 'Will Be Hard Deleted']);
    $catUuid = $category->uuid;

    expect(SyncOutbox::where('doc_uuid', $catUuid)->value('op'))->toBe('upsert');

    // Hard-delete model row directly bypassing Eloquent observer
    DB::table('categories')->where('uuid', $catUuid)->delete();

    Http::fake([
        '*/api/v1/ingest/batches' => Http::response([
            'accepted' => [$catUuid],
            'rejected' => [],
        ], 202),
    ]);

    $result = app(BatchSender::class)->run();

    expect($result->sent)->toBe(1);

    Http::assertSent(function (Request $request) use ($catUuid) {
        $decompressed = gzdecode($request->body());
        $payload = json_decode((string) $decompressed, true);

        expect($payload['documents'])->toHaveCount(1)
            ->and($payload['documents'][0]['uuid'])->toBe($catUuid)
            ->and($payload['documents'][0]['op'])->toBe('delete')
            ->and($payload['documents'][0]['data'])->toBeNull();

        return true;
    });

    expect(SyncOutbox::where('doc_uuid', $catUuid)->value('sent_at'))->not->toBeNull();
});

it('sends live rows (priority 5) before backfill rows (priority 1)', function () {
    pairPlatform();

    $backfillCat = Category::factory()->create(['name' => 'Backfill Cat']);
    SyncOutbox::where('doc_uuid', $backfillCat->uuid)->update(['priority' => 1]);

    $liveCat = Category::factory()->create(['name' => 'Live Cat']);
    // $liveCat has default priority 5

    Http::fake([
        '*/api/v1/ingest/batches' => Http::response([
            'accepted' => [$liveCat->uuid, $backfillCat->uuid],
            'rejected' => [],
        ], 202),
    ]);

    app(BatchSender::class)->run();

    Http::assertSent(function (Request $request) use ($liveCat, $backfillCat) {
        $decompressed = gzdecode($request->body());
        $payload = json_decode((string) $decompressed, true);

        expect($payload['documents'])->toHaveCount(2)
            ->and($payload['documents'][0]['uuid'])->toBe($liveCat->uuid)
            ->and($payload['documents'][1]['uuid'])->toBe($backfillCat->uuid);

        return true;
    });
});

it('does nothing when not paired', function () {
    // No pairPlatform() called
    Http::fake();

    $result = app(BatchSender::class)->run();

    Http::assertNothingSent();

    expect($result->batches)->toBe(0)
        ->and($result->sent)->toBe(0)
        ->and($result->rejected)->toBe(0)
        ->and($result->failed)->toBe(0);
});

it('splits the batch on 413 and resends smaller batch', function () {
    pairPlatform();

    $cat1 = Category::factory()->create(['name' => 'Cat A']);
    $cat2 = Category::factory()->create(['name' => 'Cat B']);

    // Sequence:
    // 1. Initial 2-doc batch -> 413 Payload Too Large
    // 2. Resend smaller 1-doc batch (cat1) -> 202 Accepted
    // 3. Released doc (cat2) in next batch -> 202 Accepted
    Http::fake([
        '*/api/v1/ingest/batches' => Http::sequence()
            ->push(['error' => 'Payload Too Large'], 413)
            ->push(['accepted' => [$cat1->uuid], 'rejected' => []], 202)
            ->push(['accepted' => [$cat2->uuid], 'rejected' => []], 202),
    ]);

    $result = app(BatchSender::class)->run();

    expect($result->batches)->toBe(2)
        ->and($result->sent)->toBe(2);

    $row1 = SyncOutbox::where('doc_uuid', $cat1->uuid)->first();
    $row2 = SyncOutbox::where('doc_uuid', $cat2->uuid)->first();

    expect($row1->sent_at)->not->toBeNull()
        ->and($row2->sent_at)->not->toBeNull();

    $recorded = Http::recorded();
    expect($recorded)->toHaveCount(3);

    // 1st request had both docs
    $req1 = json_decode((string) gzdecode($recorded[0][0]->body()), true);
    expect($req1['documents'])->toHaveCount(2);

    // 2nd request (resend of smaller batch) had 1 doc (cat1)
    $req2 = json_decode((string) gzdecode($recorded[1][0]->body()), true);
    expect($req2['documents'])->toHaveCount(1)
        ->and($req2['documents'][0]['uuid'])->toBe($cat1->uuid);

    // 3rd request had cat2 in a new batch
    $req3 = json_decode((string) gzdecode($recorded[2][0]->body()), true);
    expect($req3['documents'])->toHaveCount(1)
        ->and($req3['documents'][0]['uuid'])->toBe($cat2->uuid);
});

it('runs platform:sync artisan command and exits with 0', function () {
    pairPlatform();

    Category::factory()->create(['name' => 'Artisan Test Cat']);

    Http::fake([
        '*/api/v1/ingest/batches' => Http::response(['accepted' => [], 'rejected' => []], 202),
    ]);

    $this->artisan('platform:sync')
        ->expectsOutputToContain('Batches:')
        ->assertExitCode(0);
});
