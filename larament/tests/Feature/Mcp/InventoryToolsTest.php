<?php

use App\Enums\OptionalFeature;
use App\Mcp\Servers\BusinessServer;
use App\Mcp\Tools\DeliveryPerformanceTool;
use App\Mcp\Tools\EquipmentAndFeesTool;
use App\Mcp\Tools\InventoryMovementsTool;
use App\Mcp\Tools\InventoryStatusTool;
use App\Mcp\Tools\KitchenPerformanceTool;
use App\Mcp\Tools\ManufacturingYieldTool;
use App\Mcp\Tools\ProductDetailTool;
use App\Mcp\Tools\PurchasesSuppliersTool;
use App\Mcp\Tools\WebOrdersSummaryTool;
use App\Models\Driver;
use App\Models\InventoryItem;
use App\Models\InventoryItemMovement;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\Shift;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Module;
use App\Services\FeatureService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

/** A movement written straight to the table, so stock stays untouched. */
function mcpMove(Product $product, string $reason, string $operation, float $quantity, float $value, string $date): void
{
    InventoryItemMovement::withoutEvents(fn () => InventoryItemMovement::create([
        'product_id' => $product->id,
        'warehouse_id' => Warehouse::default()->id,
        'business_date' => $date,
        'reason' => $reason,
        'operation' => $operation,
        'quantity' => $quantity,
        'unit_cost' => $quantity ? $value / $quantity : 0,
        'total_cost' => $value,
    ]));
}

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-19 15:00:00');
    Carbon::setTestNow('2026-11-19 15:00:00');
    $this->admin = User::factory()->admin()->create();
    $this->cashier = User::factory()->cashier()->create();

    $this->flour = Product::factory()->create(['name' => 'دقيق', 'type' => 'raw_material', 'unit' => 'kg', 'cost' => 20, 'price' => 0, 'min_stock' => 50]);
    $this->oil = Product::factory()->create(['name' => 'زيت', 'type' => 'raw_material', 'unit' => 'liter', 'cost' => 80, 'price' => 0, 'min_stock' => 5]);
    $this->pizza = Product::factory()->create(['name' => 'بيتزا كبيرة', 'type' => 'manufactured', 'cost' => 60, 'price' => 150, 'min_stock' => 0]);

    InventoryItem::withoutEvents(fn () => InventoryItem::updateOrCreate(['product_id' => $this->flour->id, 'warehouse_id' => Warehouse::default()->id], ['quantity' => 30]));
    InventoryItem::withoutEvents(fn () => InventoryItem::updateOrCreate(['product_id' => $this->oil->id, 'warehouse_id' => Warehouse::default()->id], ['quantity' => 12]));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});

it('registers the inventory tools for an admin and hides them from a cashier', function () {
    $tools = [ProductDetailTool::class, InventoryStatusTool::class, InventoryMovementsTool::class, PurchasesSuppliersTool::class];

    BusinessServer::actingAs($this->admin)->tools()->assertRegistered($tools);
    BusinessServer::actingAs($this->cashier)->tools()->assertNotRegistered($tools);
});

it('refuses a direct call from a user without the report', function () {
    BusinessServer::actingAs($this->cashier)->tool(InventoryStatusTool::class, [])->assertHasErrors();
});

describe('inventory_status', function () {
    it('lists items at or below their minimum with days of cover', function () {
        mcpMove($this->flour, 'order', 'out', 60, 1200, '2026-11-10');

        $data = mcpJson(BusinessServer::actingAs($this->admin)->tool(InventoryStatusTool::class, []));

        $items = $data['data']['items'];
        expect($items)->toHaveCount(1)
            ->and($items[0]['product'])->toBe('دقيق')
            ->and($items[0]['quantity'])->toEqual(30)
            ->and($items[0]['used_last_30_days'])->toEqual(60)
            ->and($items[0]['cover_days'])->toEqual(15)
            ->and($items[0]['last_sale'])->toBe('2026-11-10');
    });

    it('shows stock levels for one product and stock value per warehouse', function () {
        $levels = mcpJson(BusinessServer::actingAs($this->admin)->tool(InventoryStatusTool::class, ['view' => 'levels', 'product' => 'زيت']));
        expect($levels['data']['items'])->toHaveCount(1)
            ->and($levels['data']['items'][0]['value'])->toEqual(960)
            ->and($levels['filters']['product'])->toBe('زيت');

        $value = mcpJson(BusinessServer::actingAs($this->admin)->tool(InventoryStatusTool::class, ['view' => 'value']));
        expect($value['data']['warehouses'][0]['items'])->toBe(2)
            ->and($value['data']['warehouses'][0]['value'])->toEqual(30 * 20 + 12 * 80);
    });

    it('lists dead stock and rejects an unknown view', function () {
        mcpMove($this->flour, 'order', 'out', 5, 100, '2026-11-15');

        $dead = mcpJson(BusinessServer::actingAs($this->admin)->tool(InventoryStatusTool::class, ['view' => 'dead']));
        expect(array_column($dead['data']['items'], 'product'))->toBe(['زيت']);

        BusinessServer::actingAs($this->admin)->tool(InventoryStatusTool::class, ['view' => 'nope'])->assertHasErrors();
    });
});

describe('inventory_movements', function () {
    it('summarises purchases, consumption and waste for the period', function () {
        mcpMove($this->flour, 'purchase', 'in', 10, 200, '2026-11-05');
        mcpMove($this->flour, 'order', 'out', 4, 80, '2026-11-06');
        mcpMove($this->oil, 'waste', 'out', 1, 80, '2026-11-07');

        $data = mcpJson(BusinessServer::actingAs($this->admin)->tool(InventoryMovementsTool::class, ['period' => 'this_month']));

        $current = $data['data']['current'];
        expect((float) $current['purchases'])->toEqual(200)
            ->and((float) $current['consumption'])->toEqual(80)
            ->and((float) $current['waste'])->toEqual(80)
            ->and($data['compared_with']['from'])->toBe('2026-10-13');
    });

    it('answers the list views with the product filter applied', function () {
        mcpMove($this->oil, 'waste', 'out', 1, 80, '2026-11-07');

        foreach (InventoryMovementsTool::VIEWS as $view) {
            BusinessServer::actingAs($this->admin)->tool(InventoryMovementsTool::class, ['view' => $view, 'period' => 'this_month'])->assertOk();
        }

        $losses = mcpJson(BusinessServer::actingAs($this->admin)->tool(InventoryMovementsTool::class, ['view' => 'losses', 'product' => 'زيت', 'period' => 'this_month']));
        expect($losses['filters']['product'])->toBe('زيت')
            ->and($losses['data']['items'])->not->toBeEmpty();
    });
});

describe('purchases_suppliers', function () {
    it('lists closed invoices, top items and net purchases per supplier', function () {
        $supplier = Supplier::factory()->create(['name' => 'مورد الأمين']);
        $invoice = PurchaseInvoice::factory()->create(['supplier_id' => $supplier->id, 'business_date' => '2026-11-04', 'closed_at' => now(), 'total' => 200]);
        PurchaseInvoice::factory()->create(['supplier_id' => $supplier->id, 'business_date' => '2026-11-05', 'closed_at' => null, 'total' => 999]);
        DB::table('purchase_invoice_items')->insert(['purchase_invoice_id' => $invoice->id, 'product_id' => $this->flour->id, 'quantity' => 10, 'price' => 20, 'total' => 200, 'created_at' => now(), 'updated_at' => now()]);
        InventoryItemMovement::withoutEvents(fn () => InventoryItemMovement::create([
            'product_id' => $this->flour->id, 'warehouse_id' => Warehouse::default()->id, 'business_date' => '2026-11-04',
            'reason' => 'purchase', 'operation' => 'in', 'quantity' => 10, 'unit_cost' => 20, 'total_cost' => 200,
            'referenceable_type' => $invoice->getMorphClass(), 'referenceable_id' => $invoice->id,
        ]));

        $data = mcpJson(BusinessServer::actingAs($this->admin)->tool(PurchasesSuppliersTool::class, ['supplier' => 'الأمين']));

        expect($data['filters']['supplier'])->toBe('مورد الأمين')
            ->and($data['data']['invoices'])->toHaveCount(1)
            ->and($data['data']['invoices'][0]['total'])->toEqual(200)
            ->and($data['data']['top_items'][0]['product'])->toBe('دقيق')
            ->and($data['data']['suppliers'])->toHaveCount(1);
    });
});

describe('product_detail', function () {
    it('returns price, margin, sales with comparison, recipe and stock', function () {
        DB::table('product_components')->insert(['product_id' => $this->pizza->id, 'component_id' => $this->flour->id, 'quantity' => 0.3, 'created_at' => now(), 'updated_at' => now()]);
        $order = mcpOrder('dine_in', 300, '2026-11-10 13:00:00');
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $this->pizza->id, 'quantity' => 2, 'price' => 150, 'cost' => 60, 'total' => 300]);
        $old = mcpOrder('dine_in', 150, '2026-10-20 13:00:00');
        OrderItem::factory()->create(['order_id' => $old->id, 'product_id' => $this->pizza->id, 'quantity' => 1, 'price' => 150, 'cost' => 60, 'total' => 150]);

        $data = mcpJson(BusinessServer::actingAs($this->admin)->tool(ProductDetailTool::class, ['product' => 'بيتزا', 'period' => 'this_month']));

        expect($data['data']['product']['name'])->toBe('بيتزا كبيرة')
            ->and($data['data']['product']['unit_margin'])->toEqual(60)
            ->and($data['data']['sales']['quantity'])->toEqual(2)
            ->and($data['data']['sales']['profit'])->toEqual(180)
            ->and($data['data']['previous']['quantity'])->toEqual(1)
            ->and($data['data']['change_percent']['quantity'])->toEqual(100)
            ->and($data['data']['recipe'][0]['component'])->toBe('دقيق')
            ->and($data['data']['weekly'])->not->toBeEmpty();
    });

    it('explains an unknown product', function () {
        BusinessServer::actingAs($this->admin)->tool(ProductDetailTool::class, ['product' => 'غير موجود'])
            ->assertHasErrors(['find_entities']);
    });
});

describe('module tools', function () {
    it('answers every module tool when its module and feature are on', function () {
        $features = app(FeatureService::class);
        $features->setModules([Module::RESTAURANT, Module::DELIVERY, Module::WEB_ORDERS, Module::MANUFACTURING]);
        $features->enable(OptionalFeature::EQUIPMENT);
        $features->enable(OptionalFeature::CUSTOM_FEES);

        $tools = [KitchenPerformanceTool::class, DeliveryPerformanceTool::class, WebOrdersSummaryTool::class, ManufacturingYieldTool::class, EquipmentAndFeesTool::class];
        BusinessServer::actingAs($this->admin)->tools()->assertRegistered($tools);

        foreach ($tools as $tool) {
            BusinessServer::actingAs($this->admin)->tool($tool, ['period' => 'this_month'])->assertOk();
        }
    });

    it('ranks drivers by delivered value like the driver report', function () {
        app(FeatureService::class)->setModules([Module::RESTAURANT, Module::DELIVERY]);
        $shift = Shift::factory()->create(['start_at' => '2026-11-18 09:00:00', 'end_at' => '2026-11-18 23:00:00']);
        $ali = Driver::factory()->create(['name' => 'علي']);
        mcpOrder('delivery', 300, '2026-11-18 13:00:00', ['shift_id' => $shift->id, 'driver_id' => $ali->id]);
        mcpOrder('delivery', 100, '2026-11-18 14:00:00', ['shift_id' => $shift->id, 'driver_id' => $ali->id, 'status' => 'cancelled']);

        $data = mcpJson(BusinessServer::actingAs($this->admin)->tool(DeliveryPerformanceTool::class, ['period' => 'this_month']));

        expect($data['data']['drivers'][0])->toMatchArray(['driver' => 'علي', 'orders' => 2, 'completed' => 1, 'completion_rate' => 50.0])
            ->and($data['data']['drivers'][0]['value'])->toEqual(300);
    });

    it('hides equipment and fees while both features are off', function () {
        $features = app(FeatureService::class);
        $features->disable(OptionalFeature::EQUIPMENT);
        $features->disable(OptionalFeature::CUSTOM_FEES);

        BusinessServer::actingAs($this->admin)->tools()->assertNotRegistered([EquipmentAndFeesTool::class]);
    });
});
