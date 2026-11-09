<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderTypeRow;
use App\Models\Shift;
use App\Services\Reports\ReportOrderTypesService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('retains inactive types only in periods containing completed orders', function () {
    OrderTypeRow::create(['code' => 'catering', 'label' => 'Catering', 'color' => 'primary', 'icon' => 'heroicon-o-star', 'is_active' => false, 'is_system' => false, 'behavior' => 'counter']);
    $shift = Shift::factory()->create();
    Order::factory()->create(['shift_id' => $shift->id, 'type' => 'catering', 'status' => OrderStatus::COMPLETED]);
    $types = app(ReportOrderTypesService::class);
    expect(array_column($types->forQuery(Order::query()->where('shift_id', $shift->id)), 'code'))->toContain('catering')
        ->and(array_column($types->forQuery(Order::query()->whereRaw('1 = 0')), 'code'))->not->toContain('catering');
});

it('reports a custom type in product and customer totals and shift cards in layout order', function () {
    $row = OrderTypeRow::create(['code' => 'catering', 'label' => 'Catering', 'color' => 'primary', 'icon' => 'heroicon-o-star', 'is_active' => true, 'is_system' => false, 'behavior' => 'counter']);
    $shift = Shift::factory()->active()->create();
    $customer = \App\Models\Customer::factory()->create();
    $product = \App\Models\Product::factory()->create(['type' => \App\Enums\ProductType::Consumable]);
    $order = Order::factory()->create(['shift_id' => $shift->id, 'customer_id' => $customer->id, 'type' => 'catering', 'status' => OrderStatus::COMPLETED, 'total' => 100, 'profit' => 60]);
    \App\Models\OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 2, 'total' => 100, 'cost' => 20]);
    app(\App\Services\SettingsService::class)->set(\App\Enums\SettingKey::CASHIER_TABS->value, [
        ['key' => 'type:catering', 'visible' => false],
        ['key' => 'type:dine_in', 'visible' => true],
    ]);

    $products = app(\App\Services\ProductsSalesReportService::class);
    $customers = app(\App\Services\CustomersPerformanceReportService::class);
    $types = $products->getReportOrderTypes(null, null, [$shift->id]);
    expect($types[0]['code'])->toBe('catering')
        ->and((float) $products->getProductsSalesPerformanceQuery(null, null, [$shift->id])->firstWhere('products.id', $product->id)->type_catering_sales)->toBe(100.0)
        ->and((float) $customers->getCustomersPerformanceQuery()->firstWhere('customers.id', $customer->id)->type_catering_sales)->toBe(100.0)
        ->and(collect(app(\App\Services\ShiftsReportService::class)->calculateOrderTypeStats($shift))->firstWhere('code', 'catering'))
        ->toMatchArray(['label' => 'Catering', 'color' => 'primary', 'count' => 1, 'value' => 100.0, 'profit' => 60.0])
        ->and(array_map(fn ($column) => $column->getName(), \App\Filament\Exports\ProductsSalesTableExporter::getColumns()))->toContain('type_catering_sales');

    $this->actingAs(\App\Models\User::factory()->admin()->create());
    \Livewire\Livewire::test(\App\Filament\Widgets\ProductsSalesTableWidget::class)
        ->assertTableColumnExists('type_catering_sales')
        ->mountTableAction('export')
        ->assertActionDataSet(['columnMap.type_catering_sales.isEnabled' => true])
        ->assertSee('Catering');
    \Livewire\Livewire::test(\App\Filament\Widgets\CustomersPerformanceTableWidget::class)
        ->assertTableColumnExists('type_catering_sales')
        ->mountTableAction('export')
        ->assertActionDataSet(['columnMap.type_catering_sales.isEnabled' => true]);
    \Livewire\Livewire::test(\App\Filament\Widgets\CurrentShiftDoneOrdersStats::class)
        ->assertSee('Catering');
    $row->update(['is_active' => false]);
    app()->forgetInstance(\App\Orders\OrderTypeCatalog::class);
    expect(array_column($products->getReportOrderTypes(null, null, [$shift->id]), 'code'))->toContain('catering')
        ->and(array_column($products->getReportOrderTypes('2000-01-01', '2000-01-02'), 'code'))->not->toContain('catering');
});

it('keeps total type aggregates separate from overall totals', function () {
    OrderTypeRow::create(['code' => 'total', 'label' => 'Total type', 'color' => 'primary', 'icon' => 'heroicon-o-star', 'is_active' => true, 'is_system' => false, 'behavior' => 'counter']);
    $shift = Shift::factory()->active()->create();
    $customer = \App\Models\Customer::factory()->create();
    $product = \App\Models\Product::factory()->create(['type' => \App\Enums\ProductType::Consumable]);
    foreach ([['total', 100, 2], ['dine_in', 50, 1]] as [$code, $amount, $quantity]) {
        $order = Order::factory()->create(['shift_id' => $shift->id, 'customer_id' => $customer->id, 'type' => $code, 'status' => OrderStatus::COMPLETED]);
        \App\Models\OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => $quantity, 'total' => $amount, 'cost' => 10]);
    }
    $products = app(\App\Services\ProductsSalesReportService::class)->getProductsSalesPerformanceQuery(null, null, [$shift->id])->firstWhere('products.id', $product->id);
    $customers = app(\App\Services\CustomersPerformanceReportService::class)->getCustomersPerformanceQuery()->firstWhere('customers.id', $customer->id);
    foreach ([$products, $customers] as $result) {
        expect((float) $result->total_quantity)->toBe(3.0)
            ->and((float) $result->total_sales)->toBe(150.0)
            ->and((float) $result->total_profit)->toBe(120.0)
            ->and((float) $result->type_total_quantity)->toBe(2.0)
            ->and((float) $result->type_total_sales)->toBe(100.0)
            ->and((float) $result->type_total_profit)->toBe(80.0);
    }
    $this->actingAs(\App\Models\User::factory()->admin()->create());
    foreach ([\App\Filament\Widgets\ProductsSalesTableWidget::class, \App\Filament\Widgets\CustomersPerformanceTableWidget::class] as $widget) {
        \Livewire\Livewire::test($widget)
            ->assertTableColumnExists('total_sales')
            ->assertTableColumnExists('type_total_sales')
            ->mountTableAction('export')
            ->assertActionDataSet(['columnMap.total_sales.isEnabled' => true, 'columnMap.type_total_sales.isEnabled' => true]);
    }
});
