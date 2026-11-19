<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Mcp\Servers\BusinessServer;
use App\Mcp\Support\ArabicText;
use App\Mcp\Tools\FindEntitiesTool;
use App\Mcp\Tools\GetBusinessContextTool;
use App\Mcp\Tools\ProductPerformanceTool;
use App\Mcp\Tools\SalesBreakdownTool;
use App\Mcp\Tools\SalesSummaryTool;
use App\Models\Category;
use App\Models\Customer;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-19 15:00:00');
    Carbon::setTestNow('2026-11-19 15:00:00');
    $this->admin = User::factory()->admin()->create();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});

describe('registration and access', function () {
    it('lists every tool for an admin', function () {
        BusinessServer::actingAs($this->admin)->tools()->assertRegistered([
            GetBusinessContextTool::class,
            FindEntitiesTool::class,
            SalesSummaryTool::class,
            SalesBreakdownTool::class,
            ProductPerformanceTool::class,
        ]);
    });

    it('hides the tools from a cashier and refuses a direct call', function () {
        $cashier = User::factory()->cashier()->create();

        BusinessServer::actingAs($cashier)->tools()->assertNotRegistered([
            SalesSummaryTool::class,
            ProductPerformanceTool::class,
            GetBusinessContextTool::class,
        ]);

        BusinessServer::actingAs($cashier)->tool(SalesSummaryTool::class)->assertHasErrors();
    });

    it('lets a report viewer use the sales tools', function () {
        BusinessServer::actingAs(User::factory()->viewer()->create())->tools()->assertRegistered([
            SalesSummaryTool::class,
            SalesBreakdownTool::class,
        ]);
    });

    it('puts the shop rules in the server instructions', function () {
        expect(BusinessServer::INSTRUCTIONS)
            ->toContain('get_business_context first')
            ->toContain('Net sales = completed orders')
            ->toContain('Modern Standard Arabic');
    });
});

describe('get_business_context', function () {
    it('returns today, channels and the data range', function () {
        mcpOrder('dine_in', 100, '2026-11-10 13:00:00');

        $data = mcpJson(BusinessServer::actingAs($this->admin)->tool(GetBusinessContextTool::class)->assertOk())['data'];

        expect($data['now']['date'])->toBe('2026-11-19')
            ->and($data['data_range']['first_order'])->toBe('2026-11-10')
            ->and($data['data_range']['completed_orders'])->toBe(1)
            ->and(collect($data['order_types'])->pluck('code'))->toContain('dine_in');
    });
});

describe('sales_summary', function () {
    it('matches net sales, orders and change against the previous period', function () {
        mcpOrder('dine_in', 300, '2026-11-05 13:00:00');
        mcpOrder('takeaway', 100, '2026-11-06 13:00:00');
        mcpOrder('takeaway', 999, '2026-11-06 14:00:00', ['status' => OrderStatus::CANCELLED]);
        mcpOrder('dine_in', 200, '2026-10-05 13:00:00'); // previous period

        $json = mcpJson(BusinessServer::actingAs($this->admin)->tool(SalesSummaryTool::class, [
            'from' => '2026-11-01',
            'to' => '2026-11-15',
        ])->assertOk());

        expect($json['period'])->toMatchArray(['from' => '2026-11-01', 'to' => '2026-11-15', 'days' => 15])
            ->and($json['compared_with'])->toBe(['from' => '2026-10-17', 'to' => '2026-10-31'])
            ->and($json['data']['current']['orders'])->toBe(2)
            ->and($json['data']['current']['net_sales'])->toEqual(400)
            ->and($json['data']['current']['cancelled'])->toBe(1)
            ->and($json['data']['current']['avg_order'])->toEqual(200)
            ->and($json['currency'])->toBe('EGP');
    });

    it('compares with the same period last year and filters by channel', function () {
        mcpOrder('dine_in', 300, '2026-11-05 13:00:00');
        mcpOrder('takeaway', 100, '2026-11-05 13:00:00');
        mcpOrder('dine_in', 150, '2025-11-05 13:00:00');

        $data = mcpJson(BusinessServer::actingAs($this->admin)->tool(SalesSummaryTool::class, [
            'from' => '2026-11-01', 'to' => '2026-11-10',
            'compare_to' => 'same_period_last_year',
            'order_types' => ['dine_in'],
        ]))['data'];

        expect($data['current']['net_sales'])->toEqual(300)
            ->and($data['previous']['net_sales'])->toEqual(150)
            ->and($data['change_percent']['net_sales'])->toEqual(100);
    });

    it('refuses periods longer than a year', function () {
        BusinessServer::actingAs($this->admin)->tool(SalesSummaryTool::class, [
            'from' => '2024-01-01', 'to' => '2026-01-01',
        ])->assertHasErrors(['Periods are limited to 366 days']);
    });

    it('resolves named periods in the shop calendar', function () {
        mcpOrder('dine_in', 50, '2026-11-18 20:00:00');
        mcpOrder('dine_in', 70, '2026-11-19 10:00:00');

        $json = mcpJson(BusinessServer::actingAs($this->admin)->tool(SalesSummaryTool::class, ['period' => 'yesterday']));

        expect($json['period']['from'])->toBe('2026-11-18')
            ->and($json['data']['current']['net_sales'])->toEqual(50)
            ->and($json)->not->toHaveKey('caveats.0');
    });
});

describe('sales_breakdown', function () {
    it('splits by channel with shares', function () {
        mcpOrder('dine_in', 300, '2026-11-05 13:00:00');
        mcpOrder('takeaway', 100, '2026-11-06 13:00:00');

        $rows = mcpJson(BusinessServer::actingAs($this->admin)->tool(SalesBreakdownTool::class, [
            'group_by' => 'channel', 'from' => '2026-11-01', 'to' => '2026-11-15',
        ]))['data']['rows'];

        expect($rows[0]['channel'])->toBe('dine_in')
            ->and($rows[0]['share'])->toEqual(75)
            ->and($rows[1]['net_sales'])->toEqual(100);
    });

    it('returns every day of the period, with the comparison', function () {
        mcpOrder('dine_in', 300, '2026-11-02 13:00:00');
        mcpOrder('dine_in', 120, '2026-10-30 13:00:00');

        $rows = mcpJson(BusinessServer::actingAs($this->admin)->tool(SalesBreakdownTool::class, [
            'group_by' => 'day', 'from' => '2026-11-01', 'to' => '2026-11-03',
        ]))['data']['rows'];

        expect($rows)->toHaveCount(3)
            ->and($rows[1]['start'])->toBe('2026-11-02')
            ->and($rows[1]['net_sales'])->toEqual(300)
            ->and($rows[0]['previous']['start'])->toBe('2026-10-29')
            ->and($rows[1]['previous']['net_sales'])->toEqual(120);
    });

    it('splits by hour, weekday and payment method', function () {
        $order = mcpOrder('dine_in', 300, '2026-11-06 13:15:00'); // Friday
        mcpOrder('dine_in', 100, '2026-11-07 20:00:00'); // Saturday
        Payment::factory()->create(['order_id' => $order->id, 'method' => PaymentMethod::CARD, 'amount' => 300]);

        $hours = mcpJson(BusinessServer::actingAs($this->admin)->tool(SalesBreakdownTool::class, [
            'group_by' => 'hour', 'from' => '2026-11-01', 'to' => '2026-11-15', 'weekday' => 'Friday',
        ]))['data']['rows'];
        expect($hours)->toBe([['hour' => '13:00', 'orders' => 1, 'sales' => 300, 'share' => 100]]);

        $days = collect(mcpJson(BusinessServer::actingAs($this->admin)->tool(SalesBreakdownTool::class, [
            'group_by' => 'weekday', 'from' => '2026-11-01', 'to' => '2026-11-15',
        ]))['data']['rows'])->keyBy('weekday');
        expect($days['Friday']['sales'])->toEqual(300)
            ->and($days['Friday']['days_in_period'])->toBe(2)
            ->and($days['Friday']['avg_sales_per_day'])->toEqual(150);

        $payments = mcpJson(BusinessServer::actingAs($this->admin)->tool(SalesBreakdownTool::class, [
            'group_by' => 'payment_method', 'from' => '2026-11-01', 'to' => '2026-11-15',
        ]))['data']['rows'];
        expect($payments[0])->toMatchArray(['payment_method' => 'card', 'sales' => 300, 'share' => 100]);
    });

    it('rejects an unknown dimension and long daily ranges', function () {
        BusinessServer::actingAs($this->admin)->tool(SalesBreakdownTool::class, ['group_by' => 'planet'])
            ->assertHasErrors(['group_by must be one of']);

        BusinessServer::actingAs($this->admin)->tool(SalesBreakdownTool::class, [
            'group_by' => 'day', 'from' => '2026-01-01', 'to' => '2026-11-01',
        ])->assertHasErrors(['Use group_by=week or month']);
    });
});

describe('product_performance', function () {
    beforeEach(function () {
        $this->main = Category::factory()->create(['name' => 'مشويات', 'parent_id' => null]);
        $this->drinks = Category::factory()->create(['name' => 'مشروبات', 'parent_id' => null]);
        $this->kofta = Product::factory()->create(['name' => 'كفتة', 'category_id' => $this->main->id]);
        $this->tea = Product::factory()->create(['name' => 'شاي', 'category_id' => $this->drinks->id]);
        $this->idle = Product::factory()->create(['name' => 'عصير قصب', 'category_id' => $this->drinks->id]);

        $order = mcpOrder('dine_in', 500, '2026-11-05 13:00:00');
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $this->kofta->id, 'quantity' => 2, 'price' => 200, 'cost' => 100, 'total' => 400]);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $this->tea->id, 'quantity' => 10, 'price' => 10, 'cost' => 2, 'total' => 100]);
    });

    it('ranks products by net sales with shares and ABC classes', function () {
        $data = mcpJson(BusinessServer::actingAs($this->admin)->tool(ProductPerformanceTool::class, [
            'from' => '2026-11-01', 'to' => '2026-11-15',
        ]))['data'];

        expect($data['rows_in_scope'])->toBe(2)
            ->and($data['rows'][0])->toMatchArray(['name' => 'كفتة', 'net_sales' => 400, 'net_profit' => 200, 'share' => 80, 'abc' => 'A'])
            ->and($data['rows'][1]['name'])->toBe('شاي');
    });

    it('ranks by quantity and filters by category name', function () {
        $data = mcpJson(BusinessServer::actingAs($this->admin)->tool(ProductPerformanceTool::class, [
            'from' => '2026-11-01', 'to' => '2026-11-15', 'rank_by' => 'net_quantity', 'category' => 'مشروب',
        ]));

        expect($data['filters']['category'])->toBe('مشروبات')
            ->and($data['data']['rows'])->toHaveCount(1)
            ->and($data['data']['rows'][0]['name'])->toBe('شاي');
    });

    it('lists products with no sales', function () {
        $data = mcpJson(BusinessServer::actingAs($this->admin)->tool(ProductPerformanceTool::class, [
            'from' => '2026-11-01', 'to' => '2026-11-15', 'rank_by' => 'no_sales',
        ]))['data'];

        expect(collect($data['rows'])->pluck('name')->all())->toContain('عصير قصب')
            ->not->toContain('كفتة');
    });

    it('rolls up to main categories', function () {
        $rows = mcpJson(BusinessServer::actingAs($this->admin)->tool(ProductPerformanceTool::class, [
            'from' => '2026-11-01', 'to' => '2026-11-15', 'level' => 'main_category',
        ]))['data']['rows'];

        expect($rows[0])->toMatchArray(['name' => 'مشويات', 'net_sales' => 400]);
    });
});

describe('find_entities', function () {
    it('matches Arabic spelling variants and masks phones', function () {
        Product::factory()->create(['name' => 'شاورمة فراخ']);
        Customer::factory()->create(['name' => 'أحمد علي', 'phone' => '01001234567']);

        $matches = mcpJson(BusinessServer::actingAs($this->admin)->tool(FindEntitiesTool::class, [
            'query' => 'شاورمه', 'kinds' => ['product'],
        ]))['data']['matches'];
        expect($matches['product'][0]['name'])->toBe('شاورمة فراخ');

        $customers = mcpJson(BusinessServer::actingAs($this->admin)->tool(FindEntitiesTool::class, [
            'query' => 'احمد', 'kinds' => ['customer'],
        ]))['data']['matches']['customer'];
        expect($customers[0])->toMatchArray(['name' => 'أحمد علي', 'phone' => '010*****567']);

        $byPhone = mcpJson(BusinessServer::actingAs($this->admin)->tool(FindEntitiesTool::class, [
            'query' => '1234567', 'kinds' => ['customer'],
        ]))['data']['matches']['customer'];
        expect($byPhone)->toHaveCount(1);
    });

    it('folds the same letters in PHP and SQL', function () {
        expect(ArabicText::normalize('  إسلامُ   آمنة  '))->toBe('اسلام امنه');
    });
});

it('never writes: the MCP code uses no write calls', function () {
    // ToolCallLog is the one writer: it appends the call log row, never shop data.
    $code = collect(File::allFiles(app_path('Mcp')))
        ->reject(fn ($file) => $file->getFilename() === 'ToolCallLog.php')
        ->map(fn ($file) => $file->getContents())
        ->implode("\n");

    foreach (['->save(', '->update(', '->delete(', '->insert(', '->create(', 'DB::statement', 'DB::update', 'DB::delete', '->forceDelete('] as $call) {
        expect($code)->not->toContain($call);
    }
});
