<?php

use App\Models\Category;
use App\Models\ExpenceType;
use App\Models\Order;
use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Supplier;
use App\Models\SyncOutbox;
use App\Models\User;
use App\Sync\BackfillService;
use App\Sync\Exceptions\PairingFailed;
use App\Sync\Exceptions\StaleLockException;
use App\Sync\PairingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    PlatformConnection::flushCache();
    Http::preventStrayRequests();
});

it('pairs and queues catalog plus 90 days of history', function () {
    $product = Product::factory()->create();
    $category = Category::factory()->create();
    $supplier = Supplier::factory()->create();
    $expenseType = ExpenceType::factory()->create();
    $staff = User::factory()->create();

    $shift = Shift::factory()->create([
        'business_date' => Carbon::today()->subDays(10)->toDateString(),
    ]);
    $order = Order::factory()->create([
        'shift_id' => $shift->id,
        'status' => 'completed',
    ]);

    Http::fake([
        'https://platform.test/api/v1/pair' => Http::response([
            'branch_uuid' => '01930c11-0000-7000-8000-000000000001',
            'token' => 'plain-text-token',
            'branch_secret' => 'plain-text-secret',
            'timezone' => 'Africa/Cairo',
        ], 200),
    ]);

    $service = app(PairingService::class);
    $connection = $service->pair('https://platform.test/', 'PAIR-1234');

    expect($connection)->toBeInstanceOf(PlatformConnection::class)
        ->and($connection->url)->toBe('https://platform.test')
        ->and($connection->branch_uuid)->toBe('01930c11-0000-7000-8000-000000000001')
        ->and($connection->token)->toBe('plain-text-token')
        ->and($connection->status)->toBe('active')
        ->and($connection->paired_at)->not->toBeNull();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://platform.test/api/v1/pair'
            && $request['code'] === 'PAIR-1234'
            && $request['pos_version'] === (string) config('app.version')
            && ! $request->hasHeader('Authorization');
    });

    // Check catalog items queued with priority 1
    expect(SyncOutbox::where('doc_type', 'product')->where('doc_uuid', $product->uuid)->where('priority', 1)->exists())->toBeTrue()
        ->and(SyncOutbox::where('doc_type', 'category')->where('doc_uuid', $category->uuid)->where('priority', 1)->exists())->toBeTrue()
        ->and(SyncOutbox::where('doc_type', 'supplier')->where('doc_uuid', $supplier->uuid)->where('priority', 1)->exists())->toBeTrue()
        ->and(SyncOutbox::where('doc_type', 'expense_type')->where('doc_uuid', $expenseType->uuid)->where('priority', 1)->exists())->toBeTrue()
        ->and(SyncOutbox::where('doc_type', 'staff')->where('doc_uuid', $staff->uuid)->where('priority', 1)->exists())->toBeTrue();

    // Check order within 90 days queued with priority 1
    expect(SyncOutbox::where('doc_type', 'order')->where('doc_uuid', $order->uuid)->where('priority', 1)->exists())->toBeTrue();
});

it('throws an Arabic error when the code is invalid', function () {
    Http::fake([
        'https://platform.test/api/v1/pair' => Http::response([
            'message' => 'Invalid or expired pairing code',
        ], 422),
    ]);

    $service = app(PairingService::class);

    try {
        $service->pair('https://platform.test', 'EXPIRED-CODE');
        test()->fail('PairingFailed was not thrown');
    } catch (PairingFailed $e) {
        expect(preg_match('/[\x{0600}-\x{06FF}]/u', $e->getMessage()))->toBe(1);
    }

    expect(PlatformConnection::count())->toBe(0);
});

it('throws an Arabic error on malformed response', function () {
    Http::fake([
        'https://platform.test/api/v1/pair' => Http::response([
            'branch_uuid' => '01930c11-0000-7000-8000-000000000001',
            // Missing token, branch_secret, timezone
        ], 200),
    ]);

    $service = app(PairingService::class);

    try {
        $service->pair('https://platform.test', 'VALID-CODE');
        test()->fail('PairingFailed was not thrown');
    } catch (PairingFailed $e) {
        expect(preg_match('/[\x{0600}-\x{06FF}]/u', $e->getMessage()))->toBe(1);
    }
});

it('revokes previous connection when pairing new one', function () {
    $old = pairPlatform('old-token');

    Http::fake([
        'https://platform.test/api/v1/pair' => Http::response([
            'branch_uuid' => '01930c11-0000-7000-8000-000000000002',
            'token' => 'new-token',
            'branch_secret' => 'new-secret',
            'timezone' => 'Africa/Cairo',
        ], 200),
    ]);

    $service = app(PairingService::class);
    $new = $service->pair('https://platform.test', 'PAIR-5678');

    expect($old->fresh()->status)->toBe('revoked')
        ->and($new->status)->toBe('active')
        ->and(PlatformConnection::active()->id)->toBe($new->id);
});

it('trims trailing slashes from platform url', function () {
    Http::fake([
        'https://platform.test/api/v1/pair' => Http::response([
            'branch_uuid' => '01930c11-0000-7000-8000-000000000001',
            'token' => 'token',
            'branch_secret' => 'secret',
            'timezone' => 'Africa/Cairo',
        ], 200),
    ]);

    $service = app(PairingService::class);
    $connection = $service->pair('https://platform.test///', 'PAIR-1234');

    expect($connection->url)->toBe('https://platform.test');
});

it('unpairs by marking connection revoked', function () {
    $connection = pairPlatform();

    $service = app(PairingService::class);
    $service->unpair();

    expect($connection->fresh()->status)->toBe('revoked')
        ->and(PlatformConnection::active())->toBeNull();
});

it('retains valid connection and token when catalog backfill fails during pairing and records failure truthfully', function () {
    Product::factory()->create();

    Http::fake([
        'https://platform.test/api/v1/pair' => Http::response([
            'branch_uuid' => '01930c11-0000-7000-8000-000000000001',
            'token' => 'plain-text-token-catalog-fail',
            'branch_secret' => 'plain-text-secret-catalog-fail',
            'timezone' => 'Africa/Cairo',
        ], 200),
    ]);

    $backfillMock = Mockery::mock(App\Sync\BackfillService::class)->makePartial();
    $backfillMock->shouldReceive('queueCatalog')->once()->andThrow(new RuntimeException('Simulated catalog backfill failure'));
    app()->instance(App\Sync\BackfillService::class, $backfillMock);

    $service = app(PairingService::class);
    $connection = $service->pair('https://platform.test', 'PAIR-CATALOG-FAIL');

    // Valid pairing is retained
    expect($connection)->toBeInstanceOf(PlatformConnection::class)
        ->and($connection->status)->toBe('active')
        ->and($connection->token)->toBe('plain-text-token-catalog-fail')
        ->and($connection->branch_uuid)->toBe('01930c11-0000-7000-8000-000000000001')
        ->and($connection->backfill_status)->toBe('failed')
        ->and($connection->backfill_catalog_queued)->toBeFalse()
        ->and($connection->backfill_range_queued)->toBeFalse()
        ->and($connection->last_error)->toContain('Simulated catalog backfill failure')
        ->and($connection->backfill_next_attempt_at)->not->toBeNull();

    expect(PlatformConnection::active()->id)->toBe($connection->id);
});

it('retains partial progress and records failure when range backfill fails after catalog succeeds', function () {
    $product = Product::factory()->create();
    $shift = Shift::factory()->create(['business_date' => Carbon::today()->subDays(5)->toDateString()]);
    Order::factory()->create(['shift_id' => $shift->id, 'status' => 'completed']);

    Http::fake([
        'https://platform.test/api/v1/pair' => Http::response([
            'branch_uuid' => '01930c11-0000-7000-8000-000000000002',
            'token' => 'plain-text-token-range-fail',
            'branch_secret' => 'plain-text-secret-range-fail',
            'timezone' => 'Africa/Cairo',
        ], 200),
    ]);

    $backfillMock = Mockery::mock(App\Sync\BackfillService::class)->makePartial();
    $backfillMock->shouldReceive('queueRange')->once()->andThrow(new RuntimeException('Simulated range backfill failure'));
    app()->instance(App\Sync\BackfillService::class, $backfillMock);

    $service = app(PairingService::class);
    $connection = $service->pair('https://platform.test', 'PAIR-RANGE-FAIL');

    // Valid pairing retained with catalog progress recorded
    expect($connection->status)->toBe('active')
        ->and($connection->token)->toBe('plain-text-token-range-fail')
        ->and($connection->backfill_catalog_queued)->toBeTrue()
        ->and($connection->backfill_range_queued)->toBeFalse()
        ->and($connection->backfill_status)->toBe('failed')
        ->and($connection->last_error)->toContain('Simulated range backfill failure');

    // Catalog item is in outbox, but order is not yet queued
    expect(SyncOutbox::where('doc_type', 'product')->where('doc_uuid', $product->uuid)->exists())->toBeTrue()
        ->and(SyncOutbox::where('doc_type', 'order')->exists())->toBeFalse();
});

it('automatically recovers failed initial backfill on retry via platform:sync without duplicate loss or losing token', function () {
    $product = Product::factory()->create();
    $shift = Shift::factory()->create(['business_date' => Carbon::today()->subDays(5)->toDateString()]);
    $order = Order::factory()->create(['shift_id' => $shift->id, 'status' => 'completed']);

    Http::fake([
        'https://platform.test/api/v1/pair' => Http::response([
            'branch_uuid' => '01930c11-0000-7000-8000-000000000003',
            'token' => 'plain-text-token-recovery',
            'branch_secret' => 'plain-text-secret-recovery',
            'timezone' => 'Africa/Cairo',
        ], 200),
        '*/api/v1/ingest/batches' => Http::response(['batch_uuid' => 'b1', 'accepted' => [], 'rejected' => []], 202),
    ]);

    // Step 1: Pair with simulated range failure
    $backfillMock = Mockery::mock(App\Sync\BackfillService::class)->makePartial();
    $backfillMock->shouldReceive('queueRange')->once()->andThrow(new RuntimeException('Temporary DB timeout'));
    app()->instance(App\Sync\BackfillService::class, $backfillMock);

    $service = app(PairingService::class);
    $connection = $service->pair('https://platform.test', 'PAIR-RECOVERY');

    expect($connection->backfill_status)->toBe('failed')
        ->and($connection->backfill_catalog_queued)->toBeTrue()
        ->and($connection->backfill_range_queued)->toBeFalse();

    $catalogCountBefore = SyncOutbox::where('doc_type', 'product')->count();
    expect($catalogCountBefore)->toBeGreaterThan(0);
    expect(SyncOutbox::where('doc_type', 'order')->count())->toBe(0);

    // Step 2: Unbind mock so real service runs for recovery
    app()->forgetInstance(App\Sync\BackfillService::class);

    // Fast-forward time past backoff
    Carbon::setTestNow(now()->addMinutes(2));
    PlatformConnection::flushCache();

    // Verify PlatformConnection::active() is a pure cached lookup with NO backfill side effects
    $active = PlatformConnection::active();
    expect($active)->not->toBeNull()
        ->and($active->fresh()->backfill_status)->toBe('failed')
        ->and($active->fresh()->backfill_lock_owner)->toBeNull()
        ->and(SyncOutbox::where('doc_type', 'order')->count())->toBe(0);

    // Trigger explicit recovery via platform:sync
    Artisan::call('platform:sync', ['--max-seconds' => 1]);

    expect($active->fresh()->backfill_status)->toBe('completed')
        ->and($active->fresh()->backfill_catalog_queued)->toBeTrue()
        ->and($active->fresh()->backfill_range_queued)->toBeTrue()
        ->and($active->fresh()->backfill_completed_at)->not->toBeNull()
        ->and($active->fresh()->backfill_lock_owner)->toBeNull()
        ->and($active->fresh()->last_error)->toBeNull()
        ->and($active->fresh()->token)->toBe('plain-text-token-recovery');

    // Catalog documents were NOT duplicated
    expect(SyncOutbox::where('doc_type', 'product')->count())->toBe($catalogCountBefore);
    // Order document is now queued
    expect(SyncOutbox::where('doc_type', 'order')->where('doc_uuid', $order->uuid)->exists())->toBeTrue();
});

it('automatically recovers incomplete initial backfill that survived process exit via platform:sync', function () {
    $product = Product::factory()->create();
    $shift = Shift::factory()->create(['business_date' => Carbon::today()->subDays(2)->toDateString()]);
    $order = Order::factory()->create(['shift_id' => $shift->id, 'status' => 'completed']);

    Http::fake([
        '*/api/v1/ingest/batches' => Http::response(['batch_uuid' => 'b1', 'accepted' => [], 'rejected' => []], 202),
    ]);

    // Simulate an interrupted pairing: connection stored in DB as pending, but process terminated
    $today = Carbon::today();
    $connection = PlatformConnection::create([
        'url' => 'https://platform.test',
        'branch_uuid' => '01930c11-0000-7000-8000-000000000004',
        'token' => 'surviving-token',
        'branch_secret' => 'surviving-secret',
        'timezone' => 'Africa/Cairo',
        'status' => 'active',
        'paired_at' => now()->subMinutes(10),
        'backfill_status' => 'pending',
        'backfill_catalog_queued' => false,
        'backfill_range_queued' => false,
        'backfill_range_from' => $today->copy()->subDays(90)->toDateString(),
        'backfill_range_to' => $today->toDateString(),
        'backfill_attempts' => 0,
        'backfill_next_attempt_at' => null,
        'backfill_lock_owner' => null,
        'backfill_locked_at' => null,
    ]);

    expect(SyncOutbox::count())->toBe(0);

    // PlatformConnection::active() does NOT trigger backfill
    PlatformConnection::flushCache();
    $active = PlatformConnection::active();
    expect($active->fresh()->backfill_status)->toBe('pending')
        ->and(SyncOutbox::count())->toBe(0);

    // Trigger explicit recovery via platform:sync
    Artisan::call('platform:sync', ['--max-seconds' => 1]);

    expect($active->fresh()->backfill_status)->toBe('completed')
        ->and($active->fresh()->backfill_catalog_queued)->toBeTrue()
        ->and($active->fresh()->backfill_range_queued)->toBeTrue()
        ->and($active->fresh()->backfill_lock_owner)->toBeNull()
        ->and($active->fresh()->token)->toBe('surviving-token')
        ->and(SyncOutbox::where('doc_type', 'product')->where('doc_uuid', $product->uuid)->exists())->toBeTrue()
        ->and(SyncOutbox::where('doc_type', 'order')->where('doc_uuid', $order->uuid)->exists())->toBeTrue();
});

it('handles concurrent recovery attempts safely without duplicate execution', function () {
    Product::factory()->create();

    $today = Carbon::today();
    $connection = PlatformConnection::create([
        'url' => 'https://platform.test',
        'branch_uuid' => '01930c11-0000-7000-8000-000000000005',
        'token' => 'concurrent-token',
        'branch_secret' => 'concurrent-secret',
        'timezone' => 'Africa/Cairo',
        'status' => 'active',
        'paired_at' => now(),
        'backfill_status' => 'in_progress',
        'backfill_catalog_queued' => false,
        'backfill_range_queued' => false,
        'backfill_range_from' => $today->copy()->subDays(90)->toDateString(),
        'backfill_range_to' => $today->toDateString(),
        'backfill_attempts' => 1,
        'backfill_lock_owner' => 'active-worker-uuid-1',
        'backfill_locked_at' => now(),
    ]);

    // Another process attempts recovery while lock is actively held
    $recovered = app(App\Sync\BackfillService::class)->recoverInitialBackfillIfNeeded($connection);

    expect($recovered)->toBeFalse()
        ->and($connection->fresh()->backfill_status)->toBe('in_progress')
        ->and($connection->fresh()->backfill_lock_owner)->toBe('active-worker-uuid-1')
        ->and(SyncOutbox::count())->toBe(0);
});

it('proves PlatformConnection::active is a pure cached lookup with no backfill side effects or writes during unrelated console commands and model observers', function () {
    $product = Product::factory()->create();

    $today = Carbon::today();
    $connection = PlatformConnection::create([
        'url' => 'https://platform.test',
        'branch_uuid' => '01930c11-0000-7000-8000-000000000006',
        'token' => 'lookup-token',
        'branch_secret' => 'lookup-secret',
        'timezone' => 'Africa/Cairo',
        'status' => 'active',
        'paired_at' => now(),
        'backfill_status' => 'pending',
        'backfill_catalog_queued' => false,
        'backfill_range_queued' => false,
        'backfill_range_from' => $today->copy()->subDays(90)->toDateString(),
        'backfill_range_to' => $today->toDateString(),
        'backfill_attempts' => 0,
        'backfill_next_attempt_at' => null,
        'backfill_lock_owner' => null,
        'backfill_locked_at' => null,
    ]);

    SyncOutbox::truncate();

    // 1. Direct active() lookup
    PlatformConnection::flushCache();
    $active = PlatformConnection::active();
    expect($active)->not->toBeNull()
        ->and($active->id)->toBe($connection->id);

    // Verify DB record is untouched
    $fresh = $connection->fresh();
    expect($fresh->backfill_status)->toBe('pending')
        ->and($fresh->backfill_attempts)->toBe(0)
        ->and($fresh->backfill_lock_owner)->toBeNull()
        ->and($fresh->backfill_locked_at)->toBeNull()
        ->and(SyncOutbox::count())->toBe(0);

    // 2. Unrelated console command execution (e.g. inspire)
    Artisan::call('inspire');

    $fresh = $connection->fresh();
    expect($fresh->backfill_status)->toBe('pending')
        ->and($fresh->backfill_attempts)->toBe(0)
        ->and(SyncOutbox::count())->toBe(0);

    // 3. Model observer firing (e.g. product updated, which calls active())
    $product->update(['name' => 'Updated Product Name']);

    $fresh = $connection->fresh();
    expect($fresh->backfill_status)->toBe('pending')
        ->and($fresh->backfill_attempts)->toBe(0)
        ->and($fresh->backfill_lock_owner)->toBeNull();

    // Only the single product observer outbox record was created, NO backfill rows
    expect(SyncOutbox::where('doc_type', 'product')->where('doc_uuid', $product->uuid)->count())->toBe(1)
        ->and(SyncOutbox::where('priority', 1)->count())->toBe(0);
});

it('ensures fixed 5-minute lease does not expire during long work when lock owner is actively maintained', function () {
    Product::factory()->create();

    $today = Carbon::today();
    $connection = PlatformConnection::create([
        'url' => 'https://platform.test',
        'branch_uuid' => '01930c11-0000-7000-8000-000000000007',
        'token' => 'long-running-token',
        'branch_secret' => 'long-running-secret',
        'timezone' => 'Africa/Cairo',
        'status' => 'active',
        'paired_at' => now(),
        'backfill_status' => 'in_progress',
        'backfill_catalog_queued' => false,
        'backfill_range_queued' => false,
        'backfill_range_from' => $today->copy()->subDays(90)->toDateString(),
        'backfill_range_to' => $today->toDateString(),
        'backfill_attempts' => 1,
        'backfill_lock_owner' => 'worker-owner-1',
        'backfill_locked_at' => now(),
    ]);

    // Fast-forward time by 6 minutes (which would have expired the old 5-minute lease)
    Carbon::setTestNow(now()->addMinutes(6));

    // Worker 1 maintains lock via heartbeat touchLock
    $backfill = app(App\Sync\BackfillService::class);
    $touched = $backfill->touchLock($connection, 'worker-owner-1');
    expect($touched)->toBeTrue();

    // Worker 2 attempts recovery during Worker 1's ongoing work
    $recovered = $backfill->recoverInitialBackfillIfNeeded($connection);

    expect($recovered)->toBeFalse()
        ->and($connection->fresh()->backfill_status)->toBe('in_progress')
        ->and($connection->fresh()->backfill_lock_owner)->toBe('worker-owner-1')
        ->and(SyncOutbox::count())->toBe(0);
});

it('recovers crashed initial backfill after lock timeout while preserving partial progress and preventing duplicates', function () {
    $product = Product::factory()->create();
    $shift = Shift::factory()->create(['business_date' => Carbon::today()->subDays(3)->toDateString()]);
    $order = Order::factory()->create(['shift_id' => $shift->id, 'status' => 'completed']);

    Http::fake([
        '*/api/v1/ingest/batches' => Http::response(['batch_uuid' => 'b1', 'accepted' => [], 'rejected' => []], 202),
    ]);

    // Pre-populate catalog in outbox (as if catalog queued before crash)
    $today = Carbon::today();
    $connection = PlatformConnection::create([
        'url' => 'https://platform.test',
        'branch_uuid' => '01930c11-0000-7000-8000-000000000008',
        'token' => 'crash-token',
        'branch_secret' => 'crash-secret',
        'timezone' => 'Africa/Cairo',
        'status' => 'active',
        'paired_at' => now()->subHours(1),
        'backfill_status' => 'in_progress',
        'backfill_catalog_queued' => true, // Catalog succeeded before crash
        'backfill_range_queued' => false,
        'backfill_range_from' => $today->copy()->subDays(90)->toDateString(),
        'backfill_range_to' => $today->toDateString(),
        'backfill_attempts' => 1,
        'backfill_lock_owner' => 'crashed-worker-uuid',
        'backfill_locked_at' => now()->subMinutes(20), // 20 mins ago (exceeds 15 min lock timeout)
    ]);

    SyncOutbox::truncate();
    // Simulate that catalog was queued before crash
    SyncOutbox::create([
        'doc_type' => 'product',
        'doc_uuid' => $product->uuid,
        'op' => 'upsert',
        'priority' => 1,
        'occurred_at' => now()->subMinutes(20),
    ]);

    $catalogCountBefore = SyncOutbox::where('doc_type', 'product')->count();
    expect($catalogCountBefore)->toBe(1);

    // Trigger explicit recovery via platform:sync
    PlatformConnection::flushCache();
    Artisan::call('platform:sync', ['--max-seconds' => 1]);

    $fresh = $connection->fresh();
    expect($fresh->backfill_status)->toBe('completed')
        ->and($fresh->backfill_catalog_queued)->toBeTrue()
        ->and($fresh->backfill_range_queued)->toBeTrue()
        ->and($fresh->backfill_lock_owner)->toBeNull()
        ->and($fresh->token)->toBe('crash-token');

    // Catalog outbox was NOT duplicated
    expect(SyncOutbox::where('doc_type', 'product')->count())->toBe($catalogCountBefore);
    // Range order was queued
    expect(SyncOutbox::where('doc_type', 'order')->where('doc_uuid', $order->uuid)->exists())->toBeTrue();
});

it('logs meaningful failure details when initial backfill fails', function () {
    Product::factory()->create();

    Http::fake([
        'https://platform.test/api/v1/pair' => Http::response([
            'branch_uuid' => '01930c11-0000-7000-8000-000000000009',
            'token' => 'logging-token',
            'branch_secret' => 'logging-secret',
            'timezone' => 'Africa/Cairo',
        ], 200),
    ]);

    Log::spy();

    $backfillMock = Mockery::mock(App\Sync\BackfillService::class)->makePartial();
    $backfillMock->shouldReceive('queueCatalog')->once()->andThrow(new RuntimeException('Detailed DB dead-lock error'));
    app()->instance(App\Sync\BackfillService::class, $backfillMock);

    $service = app(PairingService::class);
    $connection = $service->pair('https://platform.test', 'PAIR-LOGGING');

    expect($connection->status)->toBe('active')
        ->and($connection->token)->toBe('logging-token')
        ->and($connection->backfill_status)->toBe('failed')
        ->and($connection->last_error)->toContain('Detailed DB dead-lock error');

    Log::shouldHaveReceived('error')->atLeast()->once()->withArgs(function ($message, $context) use ($connection) {
        return str_contains((string) $message, 'Initial backfill')
            && isset($context['connection_id'])
            && $context['connection_id'] === $connection->id
            && isset($context['exception']);
    });
});

it('aborts slow active worker past stale threshold and prevents writing outbox rows or corrupting newer completed state', function () {
    /**
     * Concurrency and Locking Semantics:
     * - MySQL (Production): lockForUpdate() acquires an exclusive row lock on platform_connections,
     *   preventing other workers from claiming or interleaving during backfill steps.
     * - SQLite (In-Memory tests): FOR UPDATE is a no-op; transaction boundaries and lease/owner
     *   validation ensure that stale workers abort immediately without writing or overwriting state.
     * - Unverified Concurrency Limit: True OS process-level lock blocking cannot be tested in-memory on SQLite,
     *   but transactional rollback and stale owner detection are fully verified here.
     */
    $product = Product::factory()->create();
    $shift = Shift::factory()->create(['business_date' => Carbon::today()->subDays(5)->toDateString()]);
    $order = Order::factory()->create(['shift_id' => $shift->id, 'status' => 'completed']);

    $today = Carbon::today();
    $connection = PlatformConnection::create([
        'url' => 'https://platform.test',
        'branch_uuid' => '01930c11-0000-7000-8000-000000000010',
        'token' => 'stale-test-token',
        'branch_secret' => 'stale-test-secret',
        'timezone' => 'Africa/Cairo',
        'status' => 'active',
        'paired_at' => now(),
        'backfill_status' => 'pending',
        'backfill_catalog_queued' => false,
        'backfill_range_queued' => false,
        'backfill_range_from' => $today->copy()->subDays(90)->toDateString(),
        'backfill_range_to' => $today->toDateString(),
        'backfill_attempts' => 0,
        'backfill_next_attempt_at' => null,
    ]);

    SyncOutbox::truncate();

    $backfill = app(BackfillService::class);

    // Worker 1 claims a 15-minute lease (900 seconds)
    $owner1 = $backfill->claimBackfillLock($connection, 900);
    expect($owner1)->toBeString();

    // Worker 1 executes Step 1 (catalog) successfully
    $step1Result = $backfill->executeCatalogStep($connection, $owner1, 900);
    expect($step1Result)->toBeTrue();
    $catalogOutboxCount = SyncOutbox::count();
    expect($catalogOutboxCount)->toBeGreaterThan(0);

    // Now Worker 1 experiences slow active work: time advances by 16 minutes (exceeds 15 min lease)
    Carbon::setTestNow(now()->addMinutes(16));

    // Worker 2 runs recovery (e.g. background platform:sync or scheduled backfill)
    // Worker 2 sees Worker 1's lock expired and claims it
    $recoveredByWorker2 = $backfill->recoverInitialBackfillIfNeeded($connection, 900);
    expect($recoveredByWorker2)->toBeTrue();

    $freshConnection = $connection->fresh();
    expect($freshConnection->backfill_status)->toBe('completed')
        ->and($freshConnection->backfill_catalog_queued)->toBeTrue()
        ->and($freshConnection->backfill_range_queued)->toBeTrue()
        ->and($freshConnection->backfill_lock_owner)->toBeNull();

    $totalOutboxCountAfterWorker2 = SyncOutbox::count();
    expect($totalOutboxCountAfterWorker2)->toBeGreaterThan($catalogOutboxCount);

    // Now Worker 1 (stale claimant) attempts to run Step 2 using its expired owner1
    $from = Carbon::parse((string) $connection->backfill_range_from);
    $to = Carbon::parse((string) $connection->backfill_range_to);

    $staleThrew = false;
    try {
        $backfill->executeRangeStep($connection, $owner1, $from, $to, 900);
    } catch (StaleLockException $e) {
        $staleThrew = true;
    }

    expect($staleThrew)->toBeTrue();

    // Worker 1 was unable to write any new outbox records!
    expect(SyncOutbox::count())->toBe($totalOutboxCountAfterWorker2);

    // Worker 1 was unable to overwrite or corrupt newer completed state on PlatformConnection!
    $afterStale = $connection->fresh();
    expect($afterStale->backfill_status)->toBe('completed')
        ->and($afterStale->backfill_lock_owner)->toBeNull()
        ->and($afterStale->last_error)->toBeNull()
        ->and($afterStale->token)->toBe('stale-test-token');
});

it('detects self-expiration during slow active work before step execution and rolls back without writing', function () {
    Product::factory()->create();

    $today = Carbon::today();
    $connection = PlatformConnection::create([
        'url' => 'https://platform.test',
        'branch_uuid' => '01930c11-0000-7000-8000-000000000011',
        'token' => 'self-expire-token',
        'branch_secret' => 'self-expire-secret',
        'timezone' => 'Africa/Cairo',
        'status' => 'active',
        'paired_at' => now(),
        'backfill_status' => 'pending',
        'backfill_catalog_queued' => false,
        'backfill_range_queued' => false,
        'backfill_range_from' => $today->copy()->subDays(90)->toDateString(),
        'backfill_range_to' => $today->toDateString(),
        'backfill_attempts' => 0,
        'backfill_next_attempt_at' => null,
    ]);

    SyncOutbox::truncate();

    $backfill = app(BackfillService::class);
    $owner = $backfill->claimBackfillLock($connection, 300); // 5-minute lease
    expect($owner)->toBeString();

    // Advance time by 6 minutes without touchLock
    Carbon::setTestNow(now()->addMinutes(6));

    $staleThrew = false;
    try {
        $backfill->executeCatalogStep($connection, $owner, 300);
    } catch (StaleLockException $e) {
        $staleThrew = true;
    }

    expect($staleThrew)->toBeTrue()
        ->and(SyncOutbox::count())->toBe(0)
        ->and($connection->fresh()->backfill_catalog_queued)->toBeFalse();
});

it('rolls back outbox writes atomically when a step crashes mid-execution and preserves clean retryable state', function () {
    Product::factory()->create();

    $today = Carbon::today();
    $connection = PlatformConnection::create([
        'url' => 'https://platform.test',
        'branch_uuid' => '01930c11-0000-7000-8000-000000000012',
        'token' => 'crash-rollback-token',
        'branch_secret' => 'crash-rollback-secret',
        'timezone' => 'Africa/Cairo',
        'status' => 'active',
        'paired_at' => now(),
        'backfill_status' => 'pending',
        'backfill_catalog_queued' => false,
        'backfill_range_queued' => false,
        'backfill_range_from' => $today->copy()->subDays(90)->toDateString(),
        'backfill_range_to' => $today->toDateString(),
        'backfill_attempts' => 0,
        'backfill_next_attempt_at' => null,
    ]);

    SyncOutbox::truncate();

    // Simulate a crash mid-step where outbox writes are attempted before an exception occurs
    $backfillMock = Mockery::mock(BackfillService::class)->makePartial();
    $backfillMock->shouldReceive('queueCatalog')->once()->andReturnUsing(function () {
        // Write a row inside the transaction, then crash
        SyncOutbox::create([
            'doc_type' => 'product',
            'doc_uuid' => (string) Str::uuid(),
            'op' => 'upsert',
            'priority' => 1,
            'occurred_at' => now(),
        ]);
        throw new RuntimeException('Mid-step catastrophic database failure');
    });

    $success = $backfillMock->processInitialBackfill($connection);
    expect($success)->toBeFalse();

    // Crash rollback verified: the transaction rolled back the outbox write!
    expect(SyncOutbox::count())->toBe(0);

    // Connection state verified: checkpoint remains false, status is failed, credentials intact
    $fresh = $connection->fresh();
    expect($fresh->backfill_catalog_queued)->toBeFalse()
        ->and($fresh->backfill_range_queued)->toBeFalse()
        ->and($fresh->backfill_status)->toBe('failed')
        ->and($fresh->backfill_attempts)->toBe(1)
        ->and($fresh->backfill_lock_owner)->toBeNull()
        ->and($fresh->backfill_locked_at)->toBeNull()
        ->and($fresh->token)->toBe('crash-rollback-token')
        ->and($fresh->last_error)->toContain('Mid-step catastrophic database failure');

    // Fast-forward past backoff and verify recovery succeeds completely from scratch
    Carbon::setTestNow(now()->addMinutes(2));
    $realBackfill = app(BackfillService::class);
    $recovered = $realBackfill->recoverInitialBackfillIfNeeded($connection);
    expect($recovered)->toBeTrue();

    $recoveredFresh = $connection->fresh();
    expect($recoveredFresh->backfill_status)->toBe('completed')
        ->and($recoveredFresh->backfill_catalog_queued)->toBeTrue()
        ->and($recoveredFresh->backfill_range_queued)->toBeTrue()
        ->and($recoveredFresh->backfill_lock_owner)->toBeNull()
        ->and($recoveredFresh->token)->toBe('crash-rollback-token')
        ->and(SyncOutbox::count())->toBeGreaterThan(0);
});

it('guarantees PlatformConnection::active is strictly a read-only lookup with no side-effects across all lifecycle states', function () {
    $today = Carbon::today();
    $states = ['pending', 'in_progress', 'failed', 'completed'];

    foreach ($states as $state) {
        SyncOutbox::truncate();
        PlatformConnection::query()->delete();
        PlatformConnection::flushCache();

        $conn = PlatformConnection::create([
            'url' => 'https://platform.test',
            'branch_uuid' => '01930c11-0000-7000-8000-000000000013',
            'token' => "pure-lookup-{$state}-token",
            'branch_secret' => 'pure-lookup-secret',
            'timezone' => 'Africa/Cairo',
            'status' => 'active',
            'paired_at' => now(),
            'backfill_status' => $state,
            'backfill_catalog_queued' => false,
            'backfill_range_queued' => false,
            'backfill_range_from' => $today->copy()->subDays(90)->toDateString(),
            'backfill_range_to' => $today->toDateString(),
            'backfill_attempts' => 0,
            'backfill_next_attempt_at' => null,
            'backfill_lock_owner' => $state === 'in_progress' ? 'existing-owner' : null,
            'backfill_locked_at' => $state === 'in_progress' ? now() : null,
        ]);

        $beforeAttributes = $conn->fresh()->getAttributes();

        // 1. Direct active() call
        $active = PlatformConnection::active();
        expect($active)->not->toBeNull()
            ->and($active->id)->toBe($conn->id)
            ->and($active->token)->toBe("pure-lookup-{$state}-token");

        // 2. Multiple repeated calls
        PlatformConnection::active();
        PlatformConnection::active();

        // 3. Unrelated command
        Artisan::call('inspire');

        // Verify PlatformConnection record is completely unmodified
        $afterAttributes = $conn->fresh()->getAttributes();
        expect($afterAttributes)->toEqual($beforeAttributes);

        // Verify outbox remains untouched
        expect(SyncOutbox::count())->toBe(0);
    }
});
