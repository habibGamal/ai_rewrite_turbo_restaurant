<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\OrderStatus;
use App\Filament\Pages\Reports\CustomersPerformanceReport;
use App\Mcp\Support\ArabicText;
use App\Models\Customer;
use App\Services\CustomersPerformanceReportService;
use App\Services\FeatureService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('customer_insights')]
#[Title('Customer insights')]
#[Description(<<<'TEXT'
Customers who order under their name (phone): overview (customers, new vs returning, their share of sales, segments VIP/loyal/regular/occasional), top customers, at-risk customers (regulars who stopped ordering), or one customer's history. Deferred-payment balances when that feature is on.
Use for "who are our best customers", "how many new customers this month", "which regulars stopped coming", "what does customer X usually order".
Not for walk-in sales without a customer (use sales_summary). Phone numbers are masked; never ask for or repeat them.
Examples: «من أفضل عشرة عملاء هذا الشهر؟», «ما العملاء المعتادون الذين توقفوا عن الطلب؟», "How many returning customers did we have?".
TEXT)]
#[IsReadOnly]
class CustomerInsightsTool extends BusinessTool
{
    public const VIEWS = ['overview', 'top', 'at_risk', 'customer', 'receivables'];

    private readonly CustomersPerformanceReportService $customers;

    private readonly FeatureService $features;

    /** Services come from the container when the tool is built without them. */
    public function __construct(?CustomersPerformanceReportService $customers = null, ?FeatureService $features = null)
    {
        $this->customers = $customers ?? app(CustomersPerformanceReportService::class);
        $this->features = $features ?? app(FeatureService::class);
    }

    protected function reportPages(): array
    {
        return [CustomersPerformanceReport::class];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'view' => $schema->string()->enum(self::VIEWS)->description('Default overview. receivables = unpaid deferred balances (deferred payment only).'),
            'customer' => $schema->string()->description('For view=customer: the customer id, name or phone digits (find_entities gives the id).'),
            'rank_by' => $schema->string()->enum(['sales', 'orders', 'profit'])->description('For view=top. Default sales.'),
            ...$this->periodSchema($schema, compare: false, default: 'last_30_days'),
            'limit' => $schema->integer()->description('Rows for top/at_risk/receivables. Default 10, max 100.'),
        ];
    }

    protected function run(Request $request): array
    {
        $view = (string) ($request->get('view') ?? 'overview');
        if (! in_array($view, self::VIEWS, true)) {
            throw new InvalidArgumentException('view must be one of: '.implode(', ', self::VIEWS).'.');
        }

        return match ($view) {
            'overview' => $this->overview($request),
            'top' => $this->top($request),
            'at_risk' => $this->atRisk($request),
            'customer' => $this->customer($request),
            default => $this->receivables($request),
        };
    }

    /** @return array<string, mixed> */
    private function overview(Request $request): array
    {
        $period = $this->period($request, 'last_30_days', 'none');
        [$from, $to] = [$period->from->toDateString(), $period->to->toDateString()];
        $kpis = $this->customers->getKpis($from, $to);
        $round = fn (array $row) => array_map(fn ($value) => round((float) $value, 2), $row);

        $segments = [];
        foreach ($this->customers->getCustomerSegments($from, $to) as $segment => $row) {
            $segments[$segment] = ['customers' => $row['count'], 'net_sales' => round($row['total_sales'], 2), 'net_profit' => round($row['total_profit'], 2)];
        }

        $change = [];
        foreach (['customers', 'new_customers', 'sales', 'orders', 'avg_order_value'] as $key) {
            $change[$key] = self::change($kpis['current'][$key], $kpis['previous'][$key]);
        }

        return $this->envelope($period, [
            'current' => $round($kpis['current']),
            'previous_period' => $round($kpis['previous']),
            'change_percent' => $change,
            'segments' => $segments,
        ], [
            'customers' => 'Distinct named customers with a completed order in the period.',
            'new_customers' => 'Customers whose first ever completed order is in the period.',
            'return_rate' => 'Share of the period\'s customers with more than one order, percent.',
            'sales' => 'Net sales (after refunds) of orders linked to a customer.',
            'sales_share' => 'sales / all net sales including walk-ins, percent.',
            'segments' => 'VIP, loyal, regular and occasional, by the thresholds set in the customers report.',
        ]);
    }

    /** @return array<string, mixed> */
    private function top(Request $request): array
    {
        $period = $this->period($request, 'last_30_days', 'none');
        $rank = match ((string) ($request->get('rank_by') ?? 'sales')) {
            'orders' => 'total_orders',
            'profit' => 'total_profit',
            default => 'total_sales',
        };

        $rows = $this->customers->getCustomersPerformanceQuery($period->from->toDateString(), $period->to->toDateString())
            ->orderByDesc($rank)
            ->limit($this->limit($request))
            ->get()
            ->map(fn (Customer $c): array => [
                'customer_id' => $c->id,
                'name' => $c->name,
                'phone' => FindEntitiesTool::maskPhone((string) $c->phone),
                'region' => $c->region,
                'orders' => (int) $c->getAttribute('total_orders'),
                'net_sales' => round((float) $c->getAttribute('total_sales'), 2),
                'net_profit' => round((float) $c->getAttribute('total_profit'), 2),
                'avg_order' => round((float) $c->getAttribute('avg_order_value'), 2),
                'last_order' => substr((string) $c->getAttribute('last_order_date'), 0, 10),
                'segment' => $c->getAttribute('customer_segment'),
            ])->all();

        return $this->envelope($period, ['rank_by' => $rank, 'customers' => $rows], [
            'net_sales' => 'Completed orders minus refunds, in the period.',
        ]);
    }

    /** @return array<string, mixed> */
    private function atRisk(Request $request): array
    {
        $rows = $this->customers->getAtRiskCustomersQuery()
            ->orderByDesc('h.lifetime_sales')
            ->limit($this->limit($request))
            ->get()
            ->map(fn (Customer $c): array => [
                'customer_id' => $c->id,
                'name' => $c->name,
                'phone' => FindEntitiesTool::maskPhone((string) $c->phone),
                'orders' => (int) $c->getAttribute('orders_count'),
                'lifetime_sales' => round((float) $c->getAttribute('lifetime_sales'), 2),
                'last_order' => substr((string) $c->getAttribute('last_at'), 0, 10),
                'days_since_last' => (int) round((float) $c->getAttribute('days_since_last')),
                'usual_gap_days' => round((float) $c->getAttribute('usual_gap'), 1),
            ])->all();

        return $this->envelope(null, ['customers' => $rows], [
            'at_risk' => 'Customers with 2+ orders whose time since the last order is well past their usual gap (thresholds from the customers report). Uses all history.',
        ]);
    }

    /** @return array<string, mixed> */
    private function customer(Request $request): array
    {
        $search = trim((string) ($request->get('customer') ?? ''));
        if ($search === '') {
            throw new InvalidArgumentException('Give customer (id, name or phone digits).');
        }

        $query = Customer::query();
        if (ctype_digit($search) && mb_strlen($search) <= 7) {
            $query->whereKey((int) $search);
        } elseif (preg_match('/^\+?\d[\d\s-]{6,}$/', $search) === 1) {
            $query->where('phone', 'like', '%'.preg_replace('/\D/', '', $search).'%');
        } else {
            ArabicText::whereMatches($query, 'name', $search);
        }
        $matches = $query->limit(5)->get();

        if ($matches->isEmpty()) {
            throw new InvalidArgumentException("No customer matches \"{$search}\".");
        }
        if ($matches->count() > 1) {
            return $this->envelope(null, [
                'ambiguous' => true,
                'candidates' => $matches->map(fn (Customer $c) => ['customer_id' => $c->id, 'name' => $c->name, 'phone' => FindEntitiesTool::maskPhone((string) $c->phone)])->all(),
            ], caveats: ['Several customers match; ask which one, then call again with customer_id.']);
        }

        /** @var Customer $customer */
        $customer = $matches->first();
        $facts = DB::query()->fromSub($this->customers->orderFactsQuery()->where('orders.customer_id', $customer->id), 'f');

        $totals = (clone $facts)
            ->selectRaw('SUM(f.counted) as orders, SUM(f.net_sales) as sales, SUM(f.net_profit) as profit, MIN(f.created_at) as first_at, MAX(f.created_at) as last_at')
            ->first();
        $byType = (clone $facts)->select('f.type')->selectRaw('SUM(f.counted) as orders, SUM(f.net_sales) as sales')->groupBy('f.type')->get()
            ->mapWithKeys(fn ($row) => [(string) $row->type => ['orders' => (int) $row->orders, 'net_sales' => round((float) $row->sales, 2)]])->all();

        $favourites = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('orders.customer_id', $customer->id)
            ->where('orders.status', OrderStatus::COMPLETED->value)
            ->groupBy('products.id', 'products.name')
            ->select('products.name')
            ->selectRaw('SUM(order_items.quantity) as qty, SUM(order_items.total) as value')
            ->orderByDesc('qty')
            ->limit(5)
            ->get()
            ->map(fn ($row) => ['product' => $row->name, 'quantity' => (float) $row->qty, 'value' => round((float) $row->value, 2)])
            ->all();

        $recent = DB::query()->fromSub($this->customers->orderFactsQuery()->where('orders.customer_id', $customer->id), 'f')
            ->orderByDesc('f.created_at')->limit(5)->get()
            ->map(fn ($row) => ['order_id' => (int) $row->id, 'date' => substr((string) $row->created_at, 0, 16), 'type' => $row->type, 'net_sales' => round((float) $row->net_sales, 2)])
            ->all();

        $orders = (int) ($totals->orders ?? 0);
        $profile = [
            'customer_id' => $customer->id,
            'name' => $customer->name,
            'phone' => Auth::user()?->can('view_customers') ? $customer->phone : FindEntitiesTool::maskPhone((string) $customer->phone),
            'region' => $customer->region,
            'orders' => $orders,
            'net_sales' => round((float) ($totals->sales ?? 0), 2),
            'net_profit' => round((float) ($totals->profit ?? 0), 2),
            'avg_order' => $orders > 0 ? round((float) $totals->sales / $orders, 2) : 0.0,
            'first_order' => substr((string) ($totals->first_at ?? ''), 0, 10) ?: null,
            'last_order' => substr((string) ($totals->last_at ?? ''), 0, 10) ?: null,
            'by_channel' => $byType,
            'favourite_products' => $favourites,
            'recent_orders' => $recent,
        ];

        if ($this->features->deferredPaymentEnabled()) {
            $profile['unpaid_balance'] = round((float) DB::query()->fromSub($this->balances()->where('o.customer_id', $customer->id), 'b')->sum('b.due'), 2);
        }

        return $this->envelope(null, ['customer' => $profile], [
            'net_sales' => 'All-time completed orders minus refunds.',
        ], ['Do not repeat the phone number unless the user asks for it.']);
    }

    /** @return array<string, mixed> */
    private function receivables(Request $request): array
    {
        if (! $this->features->deferredPaymentEnabled()) {
            throw new InvalidArgumentException('Deferred payment is not enabled for this shop, so there are no customer balances.');
        }

        $rows = DB::query()->fromSub($this->balances(), 'b')
            ->join('customers', 'customers.id', '=', 'b.customer_id')
            ->groupBy('customers.id', 'customers.name', 'customers.phone')
            ->select('customers.id', 'customers.name', 'customers.phone')
            ->selectRaw('SUM(b.due) as due, COUNT(*) as orders, MIN(b.created_at) as oldest')
            ->orderByDesc('due')
            ->limit($this->limit($request))
            ->get();

        return $this->envelope(null, [
            'total_due' => round((float) DB::query()->fromSub($this->balances(), 'b')->sum('b.due'), 2),
            'customers' => $rows->map(fn ($row) => [
                'customer_id' => (int) $row->id,
                'name' => $row->name,
                'phone' => FindEntitiesTool::maskPhone((string) $row->phone),
                'due' => round((float) $row->due, 2),
                'unpaid_orders' => (int) $row->orders,
                'oldest_unpaid' => substr((string) $row->oldest, 0, 10),
            ])->all(),
        ], ['due' => 'Completed orders\' total minus all payments taken for them.']);
    }

    /** Completed orders with something still unpaid: one row per order. */
    private function balances(): Builder
    {
        $paid = DB::table('payments')->select('order_id')->selectRaw('SUM(amount) as paid')->groupBy('order_id');

        return DB::table('orders as o')
            ->leftJoinSub($paid, 'p', 'p.order_id', '=', 'o.id')
            ->where('o.status', OrderStatus::COMPLETED->value)
            ->whereNotNull('o.customer_id')
            ->whereRaw('o.total - COALESCE(p.paid, 0) > 0.005')
            ->select('o.id', 'o.customer_id', 'o.created_at')
            ->selectRaw('o.total - COALESCE(p.paid, 0) as due');
    }
}
