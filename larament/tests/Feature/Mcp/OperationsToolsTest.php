<?php

use App\Enums\AuditAction;
use App\Enums\OptionalFeature;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Mcp\Servers\BusinessServer;
use App\Mcp\Tools\AuditEventsTool;
use App\Mcp\Tools\CustomerInsightsTool;
use App\Mcp\Tools\ExpensesRevenuesTool;
use App\Mcp\Tools\OrdersTool;
use App\Mcp\Tools\ReturnsAnalysisTool;
use App\Mcp\Tools\ShiftsTool;
use App\Mcp\Tools\StaffPerformanceTool;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\ExpenceType;
use App\Models\Expense;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Services\FeatureService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-19 15:00:00');
    Carbon::setTestNow('2026-11-19 15:00:00');
    $this->admin = User::factory()->admin()->create(['name' => 'مدير']);
    $this->cashier = User::factory()->cashier()->create(['name' => 'سارة']);
    $this->shift = Shift::factory()->create([
        'user_id' => $this->cashier->id,
        'start_at' => '2026-11-18 09:00:00',
        'end_at' => '2026-11-18 23:00:00',
        'business_date' => '2026-11-18',
        'start_cash' => 500,
        'end_cash' => 1200,
        'real_cash' => 1150,
        'has_deficit' => true,
        'closed' => true,
    ]);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});

it('registers the operations tools for an admin and hides them from a cashier', function () {
    $tools = [OrdersTool::class, ShiftsTool::class, ExpensesRevenuesTool::class, CustomerInsightsTool::class, StaffPerformanceTool::class, AuditEventsTool::class];

    BusinessServer::actingAs($this->admin)->tools()->assertRegistered($tools);
    BusinessServer::actingAs($this->cashier)->tools()->assertNotRegistered([ShiftsTool::class, ExpensesRevenuesTool::class, CustomerInsightsTool::class, StaffPerformanceTool::class, AuditEventsTool::class]);
});

it('shows returns_analysis only while returns are on', function () {
    app(FeatureService::class)->disable(OptionalFeature::RETURNS);
    BusinessServer::actingAs($this->admin)->tools()->assertNotRegistered([ReturnsAnalysisTool::class]);

    app(FeatureService::class)->enable(OptionalFeature::RETURNS);
    BusinessServer::actingAs($this->admin)->tools()->assertRegistered([ReturnsAnalysisTool::class]);
});

describe('shifts', function () {
    it('lists shifts with sales, expected and counted cash and the difference', function () {
        $order = mcpOrder('dine_in', 700, '2026-11-18 13:00:00', ['shift_id' => $this->shift->id, 'user_id' => $this->cashier->id]);
        Payment::factory()->create(['order_id' => $order->id, 'shift_id' => $this->shift->id, 'amount' => 700, 'method' => PaymentMethod::CASH]);

        $data = mcpJson(BusinessServer::actingAs($this->admin)->tool(ShiftsTool::class, ['period' => 'yesterday']));

        $row = $data['data']['shifts'][0];
        expect($data['data']['shifts_in_period'])->toBe(1)
            ->and($row['opened_by'])->toBe('سارة')
            ->and($row['net_sales'])->toEqual(700)
            ->and($row['expected_cash'])->toEqual(1200)
            ->and($row['counted_cash'])->toEqual(1150)
            ->and($row['cash_difference'])->toEqual(-50)
            ->and($data['data']['totals']['cash_payments'])->toEqual(700);
    });

    it('gives one shift in detail and finds the open shift', function () {
        $open = Shift::factory()->create(['user_id' => $this->cashier->id, 'start_at' => '2026-11-19 09:00:00', 'end_at' => null, 'closed' => false, 'start_cash' => 300, 'end_cash' => null, 'real_cash' => null]);

        $detail = mcpJson(BusinessServer::actingAs($this->admin)->tool(ShiftsTool::class, ['shift_id' => $this->shift->id]))['data']['shift'];
        $current = mcpJson(BusinessServer::actingAs($this->admin)->tool(ShiftsTool::class, ['status' => 'open']))['data']['open_shifts'];

        expect($detail['shift_id'])->toBe($this->shift->id)
            ->and($detail)->toHaveKey('orders_by_status')
            ->and($current)->toHaveCount(1)
            ->and($current[0]['shift_id'])->toBe($open->id)
            ->and($current[0]['expected_cash'])->toEqual(300);
    });
});

describe('expenses_revenues', function () {
    it('splits expenses by type with shares and the net', function () {
        $gas = ExpenceType::factory()->create(['name' => 'غاز']);
        $rent = ExpenceType::factory()->create(['name' => 'إيجار']);
        Expense::factory()->create(['shift_id' => $this->shift->id, 'expence_type_id' => $gas->id, 'amount' => 100]);
        Expense::factory()->create(['shift_id' => $this->shift->id, 'expence_type_id' => $rent->id, 'amount' => 300]);
        mcpOrder('dine_in', 1000, '2026-11-18 13:00:00', ['shift_id' => $this->shift->id]);

        $data = mcpJson(BusinessServer::actingAs($this->admin)->tool(ExpensesRevenuesTool::class, ['period' => 'this_month', 'by_day' => true]))['data'];

        expect($data['expenses_total'])->toEqual(400)
            ->and($data['order_revenue']['net'])->toEqual(1000)
            ->and($data['expenses_share_of_net_sales'])->toEqual(40)
            ->and($data['expense_types'][0]['type'])->toBe('إيجار')
            ->and($data['expense_types'][0]['share_of_expenses'])->toEqual(75)
            ->and($data['net'])->toEqual(600)
            ->and($data['expenses_by_day'])->toEqual([['date' => '2026-11-18', 'amount' => 400]]);

        $gasOnly = mcpJson(BusinessServer::actingAs($this->admin)->tool(ExpensesRevenuesTool::class, ['expense_type' => 'غاز']))['data'];
        expect($gasOnly['expense_types'])->toHaveCount(1)->and($gasOnly['expense_types'][0]['amount'])->toEqual(100);
    });
});

describe('customer_insights', function () {
    beforeEach(function () {
        $this->ali = Customer::factory()->create(['name' => 'علي حسن', 'phone' => '01012345678']);
        $this->mona = Customer::factory()->create(['name' => 'منى', 'phone' => '01198765432']);
        mcpOrder('delivery', 300, '2026-11-10 13:00:00', ['customer_id' => $this->ali->id]);
        mcpOrder('delivery', 200, '2026-11-12 13:00:00', ['customer_id' => $this->ali->id]);
        mcpOrder('takeaway', 100, '2026-11-15 13:00:00', ['customer_id' => $this->mona->id]);
    });

    it('ranks top customers with masked phones', function () {
        $rows = mcpJson(BusinessServer::actingAs($this->admin)->tool(CustomerInsightsTool::class, ['view' => 'top']))['data']['customers'];

        expect($rows[0]['name'])->toBe('علي حسن')
            ->and($rows[0]['net_sales'])->toEqual(500)
            ->and($rows[0]['orders'])->toBe(2)
            ->and($rows[0]['phone'])->toBe('010*****678');
    });

    it('gives an overview and one customer\'s history', function () {
        $overview = mcpJson(BusinessServer::actingAs($this->admin)->tool(CustomerInsightsTool::class, []))['data'];
        expect($overview['current']['customers'])->toEqual(2)
            ->and($overview['current']['new_customers'])->toEqual(2);

        $one = mcpJson(BusinessServer::actingAs($this->admin)->tool(CustomerInsightsTool::class, ['view' => 'customer', 'customer' => 'علي']))['data']['customer'];
        expect($one['orders'])->toBe(2)
            ->and($one['net_sales'])->toEqual(500)
            ->and($one['by_channel']['delivery']['orders'])->toBe(2)
            ->and($one['recent_orders'])->toHaveCount(2);
    });

    it('refuses receivables while deferred payment is off', function () {
        app(FeatureService::class)->disable(OptionalFeature::DEFERRED_PAYMENT);

        BusinessServer::actingAs($this->admin)->tool(CustomerInsightsTool::class, ['view' => 'receivables'])
            ->assertHasErrors(['Deferred payment is not enabled']);
    });
});

describe('orders', function () {
    it('filters a list and shows one order in full', function () {
        $product = Product::factory()->create(['name' => 'كفتة']);
        $big = mcpOrder('dine_in', 2500, '2026-11-19 12:00:00', ['discount' => 100, 'user_id' => $this->cashier->id]);
        OrderItem::factory()->create(['order_id' => $big->id, 'product_id' => $product->id, 'quantity' => 2, 'price' => 1300, 'total' => 2600]);
        Payment::factory()->create(['order_id' => $big->id, 'shift_id' => $big->shift_id, 'amount' => 2500, 'method' => PaymentMethod::CARD]);
        mcpOrder('dine_in', 300, '2026-11-19 12:30:00');
        mcpOrder('takeaway', 900, '2026-11-19 13:00:00', ['status' => OrderStatus::CANCELLED]);

        $list = mcpJson(BusinessServer::actingAs($this->admin)->tool(OrdersTool::class, ['min_total' => 1000, 'has_discount' => true]))['data'];
        expect($list['matching_orders'])->toBe(1)->and($list['orders'][0]['order_id'])->toBe($big->id);

        $cancelled = mcpJson(BusinessServer::actingAs($this->admin)->tool(OrdersTool::class, ['status' => 'cancelled']))['data'];
        expect($cancelled['matching_orders'])->toBe(1);

        $one = mcpJson(BusinessServer::actingAs($this->admin)->tool(OrdersTool::class, ['order_id' => $big->id]))['data']['order'];
        expect($one['cashier'])->toBe('سارة')
            ->and($one['items'][0]['product'])->toBe('كفتة')
            ->and($one['payments'][0]['method'])->toBe('card')
            ->and($one['unpaid'])->toEqual(0);
    });

    it('caps lists at 93 days', function () {
        BusinessServer::actingAs($this->admin)->tool(OrdersTool::class, ['period' => 'this_year'])
            ->assertHasErrors(['at most 93 days']);
    });
});

describe('returns_analysis', function () {
    it('totals refunds and groups them by product', function () {
        app(FeatureService::class)->enable(OptionalFeature::RETURNS);
        $product = Product::factory()->create(['name' => 'شاي']);
        $order = mcpOrder('dine_in', 100, '2026-11-18 10:00:00', ['shift_id' => $this->shift->id]);
        $item = OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 5, 'price' => 20, 'cost' => 5, 'total' => 100]);
        $returnId = DB::table('order_returns')->insertGetId(['order_id' => $order->id, 'user_id' => $this->cashier->id, 'shift_id' => $this->shift->id, 'total_refund' => 40, 'reason' => 'بارد', 'created_at' => '2026-11-18 11:00:00', 'updated_at' => '2026-11-18 11:00:00']);
        DB::table('order_return_items')->insert(['order_return_id' => $returnId, 'order_item_id' => $item->id, 'quantity' => 2, 'refund_amount' => 40, 'restocked' => false, 'created_at' => now(), 'updated_at' => now()]);

        $data = mcpJson(BusinessServer::actingAs($this->admin)->tool(ReturnsAnalysisTool::class, ['group_by' => 'product']))['data'];

        expect($data['totals']['returns'])->toBe(1)
            ->and($data['totals']['refunded'])->toEqual(40)
            ->and($data['totals']['damaged_quantity'])->toEqual(2)
            ->and($data['rows'][0]['product'])->toBe('شاي')
            ->and($data['rows'][0]['share'])->toEqual(100);

        $byCashier = mcpJson(BusinessServer::actingAs($this->admin)->tool(ReturnsAnalysisTool::class, ['group_by' => 'cashier']))['data']['rows'];
        expect($byCashier[0]['cashier'])->toBe('سارة');
    });
});

describe('staff_performance', function () {
    it('ranks staff by discounts with cancellations', function () {
        $other = User::factory()->cashier()->create(['name' => 'أحمد']);
        mcpOrder('dine_in', 900, '2026-11-10 12:00:00', ['user_id' => $this->cashier->id, 'sub_total' => 1000, 'discount' => 100]);
        mcpOrder('dine_in', 500, '2026-11-10 12:00:00', ['user_id' => $other->id]);
        mcpOrder('dine_in', 400, '2026-11-10 13:00:00', ['user_id' => $other->id, 'status' => OrderStatus::CANCELLED]);

        $rows = mcpJson(BusinessServer::actingAs($this->admin)->tool(StaffPerformanceTool::class, ['rank_by' => 'discount']))['data']['staff'];

        expect($rows[0]['name'])->toBe('سارة')
            ->and($rows[0]['discount'])->toEqual(100)
            ->and($rows[0]['discount_rate'])->toEqual(10)
            ->and(collect($rows)->firstWhere('name', 'أحمد')['cancelled'])->toBe(1)
            ->and(collect($rows)->firstWhere('name', 'أحمد')['cancel_rate'])->toEqual(50);
    });
});

describe('audit_events', function () {
    it('merges cancellations, item removals and record changes, newest first', function () {
        $order = mcpOrder('dine_in', 100, '2026-11-19 10:00:00');
        DB::table('order_histories')->insert(['order_id' => $order->id, 'user_id' => $this->cashier->id, 'action' => 'cancelled', 'action_name' => 'إلغاء', 'description' => 'طلب العميل', 'created_at' => '2026-11-19 11:00:00', 'updated_at' => '2026-11-19 11:00:00']);
        DB::table('order_item_changes')->insert(['order_id' => $order->id, 'user_id' => $this->cashier->id, 'product_name' => 'كفتة', 'change_type' => 'removed', 'old_quantity' => 2, 'new_quantity' => 0, 'delta' => -2, 'unit_price' => 50, 'total_price' => 100, 'created_at' => '2026-11-19 10:30:00', 'updated_at' => '2026-11-19 10:30:00']);
        AuditLog::query()->create(['auditable_type' => 'shift', 'auditable_id' => $this->shift->id, 'user_id' => $this->admin->id, 'action' => AuditAction::UPDATED, 'old_values' => ['end_cash' => 1000], 'new_values' => ['end_cash' => 1200], 'reason' => 'تصحيح']);

        $data = mcpJson(BusinessServer::actingAs($this->admin)->tool(AuditEventsTool::class, []))['data'];

        expect($data['counts'])->toMatchArray(['cancellation' => 1, 'item_removed' => 1, 'record_change' => 1])
            ->and($data['events'][0]['type'])->toBe('record_change')
            ->and($data['events'][0]['reason'])->toBe('تصحيح')
            ->and(collect($data['events'])->firstWhere('type', 'item_removed')['value'])->toEqual(100)
            ->and(collect($data['events'])->firstWhere('type', 'cancellation')['by'])->toBe('سارة');

        $mine = mcpJson(BusinessServer::actingAs($this->admin)->tool(AuditEventsTool::class, ['staff' => 'سارة', 'event_types' => ['cancellation']]))['data'];
        expect($mine['counts'])->toBe(['cancellation' => 1]);
    });
});
