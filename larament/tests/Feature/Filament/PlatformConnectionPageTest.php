<?php

use App\Filament\Pages\PlatformConnectionPage;
use App\Models\PlatformConnection;
use App\Models\Shift;
use App\Models\SyncOutbox;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    PlatformConnection::flushCache();
    $this->admin = User::factory()->admin()->create();
    actingAs($this->admin);
});

afterEach(function () {
    PlatformConnection::flushCache();
});

it('admin can render platform connection page', function () {
    livewire(PlatformConnectionPage::class)
        ->assertSuccessful();
});

it('forbids users lacking manage_settings permission (gets 403)', function () {
    $cashier = User::factory()->cashier()->create();
    actingAs($cashier);

    livewire(PlatformConnectionPage::class)
        ->assertForbidden();
});

it('displays status information when disconnected', function () {
    livewire(PlatformConnectionPage::class)
        ->assertSuccessful()
        ->assertSee('غير مربوط')
        ->assertActionVisible('pair')
        ->assertActionHidden('unpair');
});

it('displays status information when connected, delayed, and revoked', function () {
    // 1. Connected
    $connection = pairPlatform();
    $connection->update([
        'last_success_at' => now()->subMinutes(5),
    ]);
    PlatformConnection::flushCache();

    livewire(PlatformConnectionPage::class)
        ->assertSuccessful()
        ->assertSee('متصل')
        ->assertActionHidden('pair')
        ->assertActionVisible('unpair');

    // 2. Delayed (last_success_at > 15 minutes)
    $connection->update([
        'last_success_at' => now()->subMinutes(20),
    ]);
    PlatformConnection::flushCache();

    livewire(PlatformConnectionPage::class)
        ->assertSuccessful()
        ->assertSee('متأخر');

    // 3. Revoked
    $connection->update([
        'status' => 'revoked',
    ]);
    PlatformConnection::flushCache();

    livewire(PlatformConnectionPage::class)
        ->assertSuccessful()
        ->assertSee('مرفوض');
});

it('pair action with Http::fake success stores an active connection', function () {
    $branchUuid = (string) Str::uuid();

    Http::fake([
        'https://platform.test/api/v1/pair' => Http::response([
            'branch_uuid' => $branchUuid,
            'token' => 'test-bearer-token',
            'branch_secret' => 'test-secret-key',
            'timezone' => 'Africa/Cairo',
        ], 200),
    ]);

    livewire(PlatformConnectionPage::class)
        ->callAction('pair', [
            'url' => 'https://platform.test',
            'code' => 'ABC12345',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $conn = PlatformConnection::active();
    expect($conn)->not->toBeNull();
    expect($conn->url)->toBe('https://platform.test');
    expect($conn->branch_uuid)->toBe($branchUuid);
    expect($conn->status)->toBe('active');
    expect($conn->paired_at)->not->toBeNull();
});

it('pair failure shows an error and stores nothing', function () {
    Http::fake([
        'https://platform.test/api/v1/pair' => Http::response([
            'message' => 'Invalid code',
        ], 422),
    ]);

    livewire(PlatformConnectionPage::class)
        ->callAction('pair', [
            'url' => 'https://platform.test',
            'code' => 'WRONG000',
        ])
        ->assertNotified();

    expect(PlatformConnection::count())->toBe(0);
    expect(PlatformConnection::active())->toBeNull();
});

it('unpair marks connection revoked', function () {
    $conn = pairPlatform();
    expect(PlatformConnection::active())->not->toBeNull();

    livewire(PlatformConnectionPage::class)
        ->callAction('unpair')
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect(PlatformConnection::active())->toBeNull();
    expect($conn->fresh()->status)->toBe('revoked');
});

it('syncs now and displays notification with sync run result counts', function () {
    pairPlatform();

    livewire(PlatformConnectionPage::class)
        ->callAction('syncNow')
        ->assertHasNoActionErrors()
        ->assertNotified();
});

it('retry-failed resets rows', function () {
    pairPlatform();

    $row1 = SyncOutbox::create([
        'doc_type' => 'order',
        'doc_uuid' => (string) Str::uuid(),
        'op' => 'upsert',
        'priority' => 5,
        'occurred_at' => now()->subHours(2),
        'attempts' => 10,
        'next_attempt_at' => now()->addHour(),
        'failed_at' => now()->subHour(),
        'last_error' => 'Platform 500 error',
    ]);

    $row2 = SyncOutbox::create([
        'doc_type' => 'shift',
        'doc_uuid' => (string) Str::uuid(),
        'op' => 'upsert',
        'priority' => 5,
        'occurred_at' => now()->subHours(3),
        'attempts' => 10,
        'next_attempt_at' => now()->addHour(),
        'failed_at' => now()->subHour(),
        'last_error' => 'Timeout',
    ]);

    livewire(PlatformConnectionPage::class)
        ->callAction('retryFailed')
        ->assertHasNoActionErrors()
        ->assertNotified();

    $row1->refresh();
    $row2->refresh();

    expect($row1->failed_at)->toBeNull();
    expect($row1->attempts)->toBe(0);
    expect($row1->next_attempt_at)->toBeNull();

    expect($row2->failed_at)->toBeNull();
    expect($row2->attempts)->toBe(0);
    expect($row2->next_attempt_at)->toBeNull();
});

it('resend-range queues rows', function () {
    pairPlatform();

    $shift = Shift::factory()->create([
        'business_date' => '2026-10-15',
    ]);

    livewire(PlatformConnectionPage::class)
        ->callAction('resendRange', [
            'from' => '2026-10-10',
            'to' => '2026-10-20',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect(SyncOutbox::where('doc_type', 'shift')->where('doc_uuid', $shift->uuid)->exists())->toBeTrue();
});

it('displays failed outbox rows in the table', function () {
    pairPlatform();

    $failedRow = SyncOutbox::create([
        'doc_type' => 'order',
        'doc_uuid' => (string) Str::uuid(),
        'op' => 'upsert',
        'priority' => 5,
        'occurred_at' => now()->subMinutes(10),
        'attempts' => 10,
        'failed_at' => now()->subMinutes(2),
        'last_error' => 'Document rejected by server',
    ]);

    $pendingRow = SyncOutbox::create([
        'doc_type' => 'order',
        'doc_uuid' => (string) Str::uuid(),
        'op' => 'upsert',
        'priority' => 5,
        'occurred_at' => now()->subMinutes(5),
        'attempts' => 0,
        'failed_at' => null,
        'sent_at' => null,
    ]);

    livewire(PlatformConnectionPage::class)
        ->assertCanSeeTableRecords([$failedRow])
        ->assertCanNotSeeTableRecords([$pendingRow]);
});
