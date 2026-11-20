<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Filament\Pages\CompaniesAccounts;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

it('lists the outstanding balance of customers with partly paid companies orders', function () {
    actingAs(User::factory()->admin()->create());

    $customer = Customer::factory()->create([
        'name' => 'شركة النيل',
        'phone' => '01000000000',
    ]);
    $order = Order::factory()->create([
        'type' => 'companies',
        'status' => OrderStatus::COMPLETED,
        'payment_status' => PaymentStatus::PARTIAL_PAID,
        'customer_id' => $customer->id,
        'total' => 100,
        'order_number' => 41,
    ]);
    Payment::factory()->create([
        'order_id' => $order->id,
        'shift_id' => $order->shift_id,
        'amount' => 60,
        'method' => PaymentMethod::CASH,
    ]);

    $settled = Customer::factory()->create(['name' => 'عميل مسدد']);
    Order::factory()->create([
        'type' => 'companies',
        'status' => OrderStatus::COMPLETED,
        'payment_status' => PaymentStatus::FULL_PAID,
        'customer_id' => $settled->id,
        'total' => 50,
    ]);

    $takeawayCustomer = Customer::factory()->create(['name' => 'عميل تيك أواي']);
    Order::factory()->create([
        'type' => 'takeaway',
        'status' => OrderStatus::COMPLETED,
        'payment_status' => PaymentStatus::PARTIAL_PAID,
        'customer_id' => $takeawayCustomer->id,
        'total' => 80,
    ]);

    livewire(CompaniesAccounts::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$customer])
        ->assertCanNotSeeTableRecords([$settled, $takeawayCustomer])
        ->assertSee('شركة النيل')
        ->assertSee('01000000000')
        ->assertSee('40.00')
        ->assertSee('#41');
});

it('lists the outstanding balance of customers with pay later (pending) companies orders', function () {
    actingAs(User::factory()->admin()->create());

    $customer = Customer::factory()->create([
        'name' => 'شركة الأهرام',
        'phone' => '01234567890',
    ]);
    Order::factory()->create([
        'type' => 'companies',
        'status' => OrderStatus::COMPLETED,
        'payment_status' => PaymentStatus::PENDING,
        'customer_id' => $customer->id,
        'total' => 200,
        'order_number' => 52,
    ]);

    livewire(CompaniesAccounts::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$customer])
        ->assertSee('شركة الأهرام')
        ->assertSee('200.00')
        ->assertSee('#52');
});
