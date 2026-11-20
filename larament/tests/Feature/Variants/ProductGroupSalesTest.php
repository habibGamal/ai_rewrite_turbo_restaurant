<?php

use App\Enums\OptionalFeature;
use App\Enums\OrderStatus;
use App\Filament\Widgets\ProductGroupSalesWidget;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\FeatureService;
use App\Services\ProductsSalesReportService;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

require_once __DIR__.'/../../Support/VariantGrid.php';

function sellSku(int $productId, float $quantity, float $price, float $cost): void
{
    $order = Order::factory()->create(['status' => OrderStatus::COMPLETED, 'created_at' => now()]);
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $productId,
        'quantity' => $quantity,
        'price' => $price,
        'cost' => $cost,
        'total' => $quantity * $price,
    ]);
}

beforeEach(function () {
    app(FeatureService::class)->enable(OptionalFeature::VARIANTS);
});

it('rolls sales up to the model with its best-selling variant', function () {
    $group = gridGroup(['المقاس' => ['S', 'M', 'L'], 'اللون' => ['أسود']]);
    sellSku(gridSku($group, 'S / أسود'), 2, 200, 90);
    sellSku(gridSku($group, 'M / أسود'), 5, 200, 90);

    $rows = app(ProductsSalesReportService::class)->getProductGroupSales(now()->toDateString(), now()->toDateString());

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['group_name'])->toBe('قميص')
        ->and($rows[0]['total_quantity'])->toBe(7.0)
        ->and($rows[0]['total_sales'])->toBe(1400.0)
        ->and($rows[0]['total_profit'])->toBe(770.0)
        ->and($rows[0]['variants_sold'])->toBe(2)
        ->and($rows[0]['variants_total'])->toBe(3)
        ->and($rows[0]['top_variant'])->toBe('M / أسود');
});

it('shows the model widget only while variants are on', function () {
    actingAs(User::factory()->admin()->create());

    expect(ProductGroupSalesWidget::canView())->toBeTrue();
    livewire(ProductGroupSalesWidget::class)->assertSuccessful();

    app(FeatureService::class)->disable(OptionalFeature::VARIANTS);
    expect(ProductGroupSalesWidget::canView())->toBeFalse();
});
