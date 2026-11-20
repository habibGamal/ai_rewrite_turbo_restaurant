<?php

use App\Models\PlatformConnection;
use App\Models\Shift;
use App\Models\SyncOutbox;
use App\Sync\HeartbeatService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    PlatformConnection::flushCache();
    Http::preventStrayRequests();
});

it('sends heartbeat payload with correct metrics', function () {
    // Open inventory day before setting outbox state
    openInventoryDay();
    $connection = pairPlatform();
    SyncOutbox::truncate();

    // Create 2 pending outbox rows
    SyncOutbox::create([
        'doc_type' => 'order',
        'doc_uuid' => (string) Str::uuid(),
        'occurred_at' => now()->subMinutes(15),
        'sent_at' => null,
        'failed_at' => null,
    ]);
    SyncOutbox::create([
        'doc_type' => 'shift',
        'doc_uuid' => (string) Str::uuid(),
        'occurred_at' => now()->subMinutes(5),
        'sent_at' => null,
        'failed_at' => null,
    ]);

    // Create 1 failed outbox row
    SyncOutbox::create([
        'doc_type' => 'product',
        'doc_uuid' => (string) Str::uuid(),
        'occurred_at' => now()->subMinutes(30),
        'failed_at' => now()->subMinutes(10),
    ]);

    Http::fake([
        'https://platform.test/api/v1/heartbeat' => Http::response([
            'commands' => [],
            'min_supported_schema' => 1,
        ], 200),
    ]);

    $service = app(HeartbeatService::class);
    $response = $service->beat();

    expect($response)->toBeArray()
        ->and($response['min_supported_schema'])->toBe(1);

    Http::assertSent(function ($request) {
        $data = $request->data();

        return $request->url() === 'https://platform.test/api/v1/heartbeat'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $data['pos_version'] === (string) config('app.version')
            && $data['pending_count'] === 2
            && $data['failed_count'] === 1
            && ! empty($data['oldest_pending_at'])
            && ! empty($data['server_time'])
            && $data['business_date_open'] === Carbon::today()->toDateString();
    });

    expect($connection->fresh()->last_heartbeat_at)->not->toBeNull();
});

it('queues a resync range received in the heartbeat response', function () {
    $shift = Shift::factory()->create([
        'business_date' => '2026-10-15',
    ]);

    pairPlatform();
    SyncOutbox::truncate();

    Http::fake([
        'https://platform.test/api/v1/heartbeat' => Http::response([
            'commands' => [
                [
                    'type' => 'resync',
                    'from' => '2026-10-10',
                    'to' => '2026-10-20',
                ],
            ],
            'min_supported_schema' => 1,
        ], 200),
    ]);

    $service = app(HeartbeatService::class);
    $service->beat();

    expect(SyncOutbox::where('doc_type', 'shift')->where('doc_uuid', $shift->uuid)->where('priority', 1)->exists())->toBeTrue();
});

it('rotates the token on command', function () {
    $connection = pairPlatform('initial-secret-token');

    Http::fake([
        'https://platform.test/api/v1/heartbeat' => Http::response([
            'commands' => [
                ['type' => 'rotate_token'],
            ],
            'min_supported_schema' => 1,
        ], 200),
        'https://platform.test/api/v1/pair/rotate' => Http::response([
            'token' => 'brand-new-rotated-token',
        ], 200),
    ]);

    $service = app(HeartbeatService::class);
    $service->beat();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://platform.test/api/v1/pair/rotate'
            && $request->hasHeader('Authorization', 'Bearer initial-secret-token');
    });

    expect($connection->fresh()->token)->toBe('brand-new-rotated-token');
});

it('ignores unknown commands', function () {
    pairPlatform();

    Http::fake([
        'https://platform.test/api/v1/heartbeat' => Http::response([
            'commands' => [
                ['type' => 'future_unsupported_command', 'foo' => 'bar'],
            ],
            'min_supported_schema' => 1,
        ], 200),
    ]);

    $service = app(HeartbeatService::class);
    $response = $service->beat();

    expect($response)->toBeArray();
});

it('flags connection revoked on 401', function () {
    $connection = pairPlatform('bad-token');

    Http::fake([
        'https://platform.test/api/v1/heartbeat' => Http::response([
            'message' => 'Unauthenticated',
        ], 401),
    ]);

    $service = app(HeartbeatService::class);

    expect(fn () => $service->beat())->toThrow(\RuntimeException::class, 'token rejected');

    expect($connection->fresh()->status)->toBe('revoked')
        ->and(PlatformConnection::active())->toBeNull();
});

it('throws and preserves last_error on 5xx response and fails command', function () {
    $connection = pairPlatform();

    Http::fake([
        'https://platform.test/api/v1/heartbeat' => Http::response('Server Error', 500),
    ]);

    $service = app(HeartbeatService::class);

    expect(fn () => $service->beat())->toThrow(\RuntimeException::class, 'HTTP status 500');

    expect($connection->fresh()->last_error)->toContain('500');

    $this->artisan('platform:heartbeat')
        ->assertFailed()
        ->expectsOutputToContain('Heartbeat failed: Heartbeat failed with HTTP status 500');
});

it('throws and preserves last_error on network failure and fails command', function () {
    $connection = pairPlatform();

    Http::fake([
        'https://platform.test/api/v1/heartbeat' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection timed out'),
    ]);

    $service = app(HeartbeatService::class);

    expect(fn () => $service->beat())->toThrow(\RuntimeException::class, 'Connection timed out');

    expect($connection->fresh()->last_error)->toContain('Connection timed out');

    $this->artisan('platform:heartbeat')
        ->assertFailed()
        ->expectsOutputToContain('Heartbeat failed: Heartbeat failed: Connection timed out');
});

it('isolates malformed resync commands and continues execution', function () {
    $connection = pairPlatform();
    SyncOutbox::truncate();

    Http::fake([
        'https://platform.test/api/v1/heartbeat' => Http::response([
            'commands' => [
                // Malformed date string
                ['type' => 'resync', 'from' => 'not-a-date', 'to' => '2026-10-20'],
                // Inverted range (from > to)
                ['type' => 'resync', 'from' => '2026-10-25', 'to' => '2026-10-10'],
                // Non-string dates
                ['type' => 'resync', 'from' => 12345, 'to' => false],
                // Valid subsequent command: rotate_token
                ['type' => 'rotate_token'],
            ],
            'min_supported_schema' => 1,
        ], 200),
        'https://platform.test/api/v1/pair/rotate' => Http::response([
            'token' => 'rotated-after-isolated-resync',
        ], 200),
    ]);

    $service = app(HeartbeatService::class);
    $response = $service->beat();

    expect($response)->toBeArray()
        ->and($connection->fresh()->token)->toBe('rotated-after-isolated-resync')
        ->and(SyncOutbox::where('priority', 1)->count())->toBe(0);
});

it('protects token rotation network failures and preserves last_error', function () {
    $connection = pairPlatform('current-token');

    Http::fake([
        'https://platform.test/api/v1/heartbeat' => Http::response([
            'commands' => [
                ['type' => 'rotate_token'],
            ],
            'min_supported_schema' => 1,
        ], 200),
        'https://platform.test/api/v1/pair/rotate' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Rotation endpoint unreachable'),
    ]);

    $service = app(HeartbeatService::class);

    // Heartbeat itself must not crash from rotation network failure
    $response = $service->beat();

    expect($response)->toBeArray()
        ->and($connection->fresh()->token)->toBe('current-token')
        ->and($connection->fresh()->last_error)->toContain('Rotation endpoint unreachable');
});

it('does nothing when not paired', function () {
    $service = app(HeartbeatService::class);
    $response = $service->beat();

    expect($response)->toBe([])
        ->and(Http::recorded())->toBeEmpty();
});

it('succeeds platform:heartbeat artisan command when paired and when not paired', function () {
    // When not paired (no-op)
    PlatformConnection::flushCache();
    $this->artisan('platform:heartbeat')
        ->assertSuccessful()
        ->expectsOutputToContain('Heartbeat sent successfully');

    // When paired
    pairPlatform();
    Http::fake([
        'https://platform.test/api/v1/heartbeat' => Http::response([
            'commands' => [],
            'min_supported_schema' => 1,
        ], 200),
    ]);

    $this->artisan('platform:heartbeat')
        ->assertSuccessful()
        ->expectsOutputToContain('Heartbeat sent successfully');
});
