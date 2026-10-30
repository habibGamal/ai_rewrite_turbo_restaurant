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
use App\Sync\Exceptions\PairingFailed;
use App\Sync\PairingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

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
