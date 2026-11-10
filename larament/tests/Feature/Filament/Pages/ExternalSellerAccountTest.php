<?php

use App\Filament\Pages\ExternalSellerAccount;
use App\Filament\Pages\ExternalSellerAccount\SettlementsTable;
use App\Filament\Pages\ExternalSellerAccounts;
use App\Models\ExternalOrder;
use App\Models\ExternalSeller;
use App\Models\ExternalSellerSettlement;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use Carbon\Carbon;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->shift = Shift::factory()->active()->create(['user_id' => $this->admin->id]);
});

it('refuses access to users without view_external_sellers permission', function () {
    $user = User::factory()->create();
    $user->syncRoles([]);
    $seller = ExternalSeller::create(['name' => 'عربة الصوص', 'is_active' => true]);

    actingAs($user);

    livewire(ExternalSellerAccount::class, ['record' => $seller->id])
        ->assertForbidden();
});

it('renders the seller account page for an authorized user', function () {
    actingAs($this->admin);

    $seller = ExternalSeller::create(['name' => 'عربة الصوص', 'phone' => '01012345678', 'is_active' => true]);

    livewire(ExternalSellerAccount::class, ['record' => $seller->id])
        ->assertSuccessful()
        ->assertSee('عربة الصوص')
        ->assertSee('عدد الطلبات')
        ->assertSee('إجمالي الطلبات')
        ->assertSee('المحصل')
        ->assertSee('المدفوع للبائع')
        ->assertSee('المستحق')
        ->assertSee('المستحق الكلي حتى الآن (لجميع الفترات)')
        ->assertActionExists('pay');
});

it('has a link to the seller account page from ExternalSellerAccounts', function () {
    actingAs($this->admin);

    $seller = ExternalSeller::create(['name' => 'عربة الصوص', 'is_active' => true]);

    livewire(ExternalSellerAccounts::class)
        ->assertSuccessful()
        ->assertTableActionExists('view_account')
        ->assertSee('عرض الحساب');
});

it('matches the stats and filtering example with seller عربة الصوص', function () {
    actingAs($this->admin);

    $seller = ExternalSeller::create(['name' => 'عربة الصوص', 'is_active' => true]);

    // Order 1: Nov 5, total 40, collected 40
    $host1 = Order::factory()->create([
        'user_id' => $this->admin->id,
        'shift_id' => $this->shift->id,
        'order_number' => 101,
        'created_at' => Carbon::parse('2026-11-05 12:00:00'),
    ]);
    $ext1 = ExternalOrder::create([
        'host_order_id' => $host1->id,
        'external_seller_id' => $seller->id,
        'seller_name' => $seller->name,
        'status' => 'fulfilled',
        'total' => 40.00,
        'created_at' => Carbon::parse('2026-11-05 12:00:00'),
    ]);
    $ext1->items()->create(['name' => 'صوص حار', 'quantity' => 1, 'price' => 40, 'total' => 40]);
    $ext1->collections()->create([
        'shift_id' => $this->shift->id,
        'user_id' => $this->admin->id,
        'method' => 'cash',
        'amount' => 40.00,
        'created_at' => Carbon::parse('2026-11-05 12:00:00'),
    ]);

    // Order 2: Nov 15, total 60, collected 60
    $host2 = Order::factory()->create([
        'user_id' => $this->admin->id,
        'shift_id' => $this->shift->id,
        'order_number' => 102,
        'created_at' => Carbon::parse('2026-11-15 14:00:00'),
    ]);
    $ext2 = ExternalOrder::create([
        'host_order_id' => $host2->id,
        'external_seller_id' => $seller->id,
        'seller_name' => $seller->name,
        'status' => 'fulfilled',
        'total' => 60.00,
        'created_at' => Carbon::parse('2026-11-15 14:00:00'),
    ]);
    $ext2->items()->create(['name' => 'صوص باربكيو', 'quantity' => 2, 'price' => 30, 'total' => 60]);
    $ext2->collections()->create([
        'shift_id' => $this->shift->id,
        'user_id' => $this->admin->id,
        'method' => 'cash',
        'amount' => 60.00,
        'created_at' => Carbon::parse('2026-11-15 14:00:00'),
    ]);

    // Order 3: Nov 20, total 100, collected 60 (partially collected)
    $host3 = Order::factory()->create([
        'user_id' => $this->admin->id,
        'shift_id' => $this->shift->id,
        'order_number' => 103,
        'created_at' => Carbon::parse('2026-11-20 16:00:00'),
    ]);
    $ext3 = ExternalOrder::create([
        'host_order_id' => $host3->id,
        'external_seller_id' => $seller->id,
        'seller_name' => $seller->name,
        'status' => 'pending',
        'total' => 100.00,
        'created_at' => Carbon::parse('2026-11-20 16:00:00'),
    ]);
    $ext3->items()->create(['name' => 'سوشي', 'quantity' => 2, 'price' => 45, 'total' => 90]);
    $ext3->items()->create(['name' => 'صوص', 'quantity' => 1, 'price' => 10, 'total' => 10]);
    $ext3->collections()->create([
        'shift_id' => $this->shift->id,
        'user_id' => $this->admin->id,
        'method' => 'card',
        'amount' => 60.00,
        'created_at' => Carbon::parse('2026-11-20 16:00:00'),
    ]);

    // Settlement: Paid out 100 on Nov 25
    ExternalSellerSettlement::create([
        'external_seller_id' => $seller->id,
        'shift_id' => $this->shift->id,
        'user_id' => $this->admin->id,
        'amount' => 100.00,
        'note' => 'دفعة أسبوعية',
        'created_at' => Carbon::parse('2026-11-25 18:00:00'),
    ]);

    // 1. Without filters: 3 orders, total 200, collected 160, paid 100, payable 60
    $component = livewire(ExternalSellerAccount::class, ['record' => $seller->id])
        ->assertSuccessful()
        ->assertSee('3')
        ->assertSee('200.00')
        ->assertSee('160.00')
        ->assertSee('100.00')
        ->assertSee('60.00')
        ->assertCanSeeTableRecords([$ext1, $ext2, $ext3]);

    expect($component->instance()->getStats())->toBe([
        'orders_count' => 3,
        'orders_total' => 200.0,
        'collected' => 160.0,
        'paid' => 100.0,
        'payable' => 60.0,
    ]);

    // 2. Filter to 1-10 November with one order of 40: shows 1 / 40 / 40 / 0 / 60
    $filteredComponent = livewire(ExternalSellerAccount::class, ['record' => $seller->id])
        ->filterTable('date', [
            'from' => '2026-11-01',
            'to' => '2026-11-10',
        ])
        ->assertSuccessful()
        ->assertSee('1')
        ->assertSee('40.00')
        ->assertSee('60.00')
        ->assertCanSeeTableRecords([$ext1])
        ->assertCanNotSeeTableRecords([$ext2, $ext3]);

    expect($filteredComponent->instance()->getStats())->toBe([
        'orders_count' => 1,
        'orders_total' => 40.0,
        'collected' => 40.0,
        'paid' => 0.0,
        'payable' => 60.0, // all-time figure
    ]);
});

it('filters the orders table by shift', function () {
    actingAs($this->admin);

    $shift2 = Shift::factory()->create(['user_id' => $this->admin->id]);
    $seller = ExternalSeller::create(['name' => 'بائع الشفتات', 'is_active' => true]);

    $host1 = Order::factory()->create(['shift_id' => $this->shift->id, 'user_id' => $this->admin->id]);
    $order1 = ExternalOrder::create([
        'host_order_id' => $host1->id,
        'external_seller_id' => $seller->id,
        'seller_name' => $seller->name,
        'total' => 50,
    ]);

    $host2 = Order::factory()->create(['shift_id' => $shift2->id, 'user_id' => $this->admin->id]);
    $order2 = ExternalOrder::create([
        'host_order_id' => $host2->id,
        'external_seller_id' => $seller->id,
        'seller_name' => $seller->name,
        'total' => 75,
    ]);

    livewire(ExternalSellerAccount::class, ['record' => $seller->id])
        ->filterTable('shift', $this->shift->id)
        ->assertCanSeeTableRecords([$order1])
        ->assertCanNotSeeTableRecords([$order2]);
});

it('filters the orders table by status', function () {
    actingAs($this->admin);

    $seller = ExternalSeller::create(['name' => 'بائع الحالات', 'is_active' => true]);

    $host1 = Order::factory()->create(['shift_id' => $this->shift->id, 'user_id' => $this->admin->id]);
    $pending = ExternalOrder::create([
        'host_order_id' => $host1->id,
        'external_seller_id' => $seller->id,
        'seller_name' => $seller->name,
        'status' => 'pending',
        'total' => 50,
    ]);

    $host2 = Order::factory()->create(['shift_id' => $this->shift->id, 'user_id' => $this->admin->id]);
    $fulfilled = ExternalOrder::create([
        'host_order_id' => $host2->id,
        'external_seller_id' => $seller->id,
        'seller_name' => $seller->name,
        'status' => 'fulfilled',
        'total' => 75,
    ]);

    livewire(ExternalSellerAccount::class, ['record' => $seller->id])
        ->filterTable('status', 'pending')
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$fulfilled]);
});

it('filters the orders table by uncollected', function () {
    actingAs($this->admin);

    $seller = ExternalSeller::create(['name' => 'بائع التحصيل', 'is_active' => true]);

    // Uncollected order: total 100, collected 40
    $host1 = Order::factory()->create(['shift_id' => $this->shift->id, 'user_id' => $this->admin->id]);
    $uncollected = ExternalOrder::create([
        'host_order_id' => $host1->id,
        'external_seller_id' => $seller->id,
        'seller_name' => $seller->name,
        'total' => 100,
    ]);
    $uncollected->collections()->create([
        'shift_id' => $this->shift->id,
        'user_id' => $this->admin->id,
        'method' => 'cash',
        'amount' => 40,
    ]);

    // Fully collected order: total 50, collected 50
    $host2 = Order::factory()->create(['shift_id' => $this->shift->id, 'user_id' => $this->admin->id]);
    $fullyCollected = ExternalOrder::create([
        'host_order_id' => $host2->id,
        'external_seller_id' => $seller->id,
        'seller_name' => $seller->name,
        'total' => 50,
    ]);
    $fullyCollected->collections()->create([
        'shift_id' => $this->shift->id,
        'user_id' => $this->admin->id,
        'method' => 'cash',
        'amount' => 50,
    ]);

    livewire(ExternalSellerAccount::class, ['record' => $seller->id])
        ->filterTable('uncollected', true)
        ->assertCanSeeTableRecords([$uncollected])
        ->assertCanNotSeeTableRecords([$fullyCollected]);
});

it('allows paying settlements from the page header and refreshes stats', function () {
    actingAs($this->admin);

    $seller = ExternalSeller::create(['name' => 'عربة الصوص', 'is_active' => true]);

    $host = Order::factory()->create(['shift_id' => $this->shift->id, 'user_id' => $this->admin->id]);
    $ext = ExternalOrder::create([
        'host_order_id' => $host->id,
        'external_seller_id' => $seller->id,
        'seller_name' => $seller->name,
        'total' => 100,
    ]);
    $ext->collections()->create([
        'shift_id' => $this->shift->id,
        'user_id' => $this->admin->id,
        'method' => 'cash',
        'amount' => 100,
    ]);

    livewire(ExternalSellerAccount::class, ['record' => $seller->id])
        ->assertSee('100.00') // payable = 100
        ->callAction('pay', [
            'amount' => 40,
            'note' => 'دفعة جزئية',
        ])
        ->assertHasNoActionErrors()
        ->assertSee('60.00'); // payable refreshed to 60!

    expect($seller->settlements()->count())->toBe(1)
        ->and((float) $seller->settlements()->first()->amount)->toBe(40.0);
});

it('renders settlements table and filters by date range', function () {
    actingAs($this->admin);

    $seller = ExternalSeller::create(['name' => 'بائع التسويات', 'is_active' => true]);

    $s1 = ExternalSellerSettlement::create([
        'external_seller_id' => $seller->id,
        'shift_id' => $this->shift->id,
        'user_id' => $this->admin->id,
        'amount' => 50.00,
        'note' => 'دفعة أولى',
        'created_at' => Carbon::parse('2026-11-03 10:00:00'),
    ]);

    $s2 = ExternalSellerSettlement::create([
        'external_seller_id' => $seller->id,
        'shift_id' => $this->shift->id,
        'user_id' => $this->admin->id,
        'amount' => 80.00,
        'note' => 'دفعة ثانية',
        'created_at' => Carbon::parse('2026-11-20 10:00:00'),
    ]);

    livewire(SettlementsTable::class, ['sellerId' => $seller->id])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$s1, $s2])
        ->filterTable('date', [
            'from' => '2026-11-01',
            'to' => '2026-11-10',
        ])
        ->assertCanSeeTableRecords([$s1])
        ->assertCanNotSeeTableRecords([$s2]);
});
