<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\OrderHistory;
use App\Models\OrderItem;
use App\Models\OrderItemChange;
use App\Models\Payment;
use App\Models\User;
use App\Services\FeatureService;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('orders')]
#[Title('Orders')]
#[Description(<<<'TEXT'
Individual orders. Give order_id (or order_number) for one order in full: items, payments, discount, customer, cashier and its history (created, discount, payments, cancellation, item changes, returns). Or filter a list: status, channel, cashier, customer, min/max total, with a discount, unpaid.
Use for "show order 152", "cancelled orders yesterday", "orders over 2,000 with a discount", "unpaid orders this week".
Not for totals or trends (use sales_summary / sales_breakdown). Lists are capped at 50 rows per page over at most 93 days.
Examples: «اعرض تفاصيل الطلب رقم 152», «ما الطلبات الملغاة أمس؟», "Orders above 1000 EGP with a discount this week".
TEXT)]
#[IsReadOnly]
class OrdersTool extends BusinessTool
{
    private const MAX_DAYS = 93;

    private const PAGE = 50;

    private readonly FeatureService $features;

    /** Services come from the container when the tool is built without them. */
    public function __construct(?FeatureService $features = null)
    {
        $this->features = $features ?? app(FeatureService::class);
    }

    protected function reportPages(): array
    {
        return [];
    }

    public function allowed(): bool
    {
        return Auth::user() !== null && OrderResource::canViewAny();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'order_id' => $schema->integer()->description('One order in full.'),
            'order_number' => $schema->string()->description('One order by the number printed on the receipt (the latest one with that number in the period).'),
            ...$this->periodSchema($schema, compare: false, default: 'today'),
            'status' => $schema->string()->enum(array_map(fn (OrderStatus $s) => $s->value, OrderStatus::cases()))->description('Default: all.'),
            'order_type' => $schema->string()->description('Channel code from get_business_context (dine_in, takeaway, delivery...).'),
            'cashier' => $schema->string()->description('Staff name or id who created the order.'),
            'customer_id' => $schema->integer()->description('From find_entities.'),
            'min_total' => $schema->number(),
            'max_total' => $schema->number(),
            'has_discount' => $schema->boolean(),
            'unpaid' => $schema->boolean()->description('Completed orders not fully paid (deferred payment).'),
            'sort' => $schema->string()->enum(['newest', 'oldest', 'highest_total', 'highest_discount'])->description('Default newest.'),
            'page' => $schema->integer()->description('Page of '.self::PAGE.' rows, from 1.'),
        ];
    }

    protected function run(Request $request): array
    {
        if ($request->get('order_id') !== null) {
            return $this->envelope(null, ['order' => $this->detail($this->find(Order::query()->whereKey((int) $request->get('order_id'))))]);
        }

        $period = $this->period($request, 'today', 'none');
        if ($period->days() > self::MAX_DAYS) {
            throw new InvalidArgumentException('Order lists cover at most '.self::MAX_DAYS.' days. Ask for a shorter period.');
        }
        $start = Carbon::parse($period->from->toDateString())->startOfDay();
        $end = Carbon::parse($period->to->toDateString())->endOfDay();

        if ($request->get('order_number') !== null) {
            $order = $this->find(Order::query()
                ->where('order_number', (string) $request->get('order_number'))
                ->whereBetween('created_at', [$start, $end])
                ->latest('id'));

            return $this->envelope($period, ['order' => $this->detail($order)]);
        }

        $query = Order::query()->with(['user', 'customer'])->whereBetween('orders.created_at', [$start, $end]);
        $filters = $this->applyFilters($query, $request);

        $count = (clone $query)->count();
        $sum = (float) (clone $query)->sum('total');
        $page = max(1, (int) ($request->get('page') ?? 1));

        match ((string) ($request->get('sort') ?? 'newest')) {
            'oldest' => $query->orderBy('orders.id'),
            'highest_total' => $query->orderByDesc('total'),
            'highest_discount' => $query->orderByDesc('discount'),
            default => $query->orderByDesc('orders.id'),
        };

        $rows = $query->forPage($page, self::PAGE)->get()->map(fn (Order $order) => $this->row($order))->all();

        return $this->envelope($period, [
            'matching_orders' => $count,
            'matching_total' => round($sum, 2),
            'page' => $page,
            'pages' => (int) max(1, ceil($count / self::PAGE)),
            'orders' => $rows,
        ], [
            'total' => 'Order total after discount, with service, tax, fees and equipment.',
            'matching_total' => 'Sum of total over every matching order, all pages.',
        ], [], $filters);
    }

    /** @param  Builder<Order>  $query */
    private function find(Builder $query): Order
    {
        $order = $query->with(['user', 'customer', 'items.product', 'payments'])->first();
        if (! $order instanceof Order) {
            throw new InvalidArgumentException('No order found. Check the id or number, or widen the period for order_number.');
        }

        return $order;
    }

    /**
     * @param  Builder<Order>  $query
     * @return array<string, mixed>
     */
    private function applyFilters(Builder $query, Request $request): array
    {
        $filters = [];

        if (($status = $request->get('status')) !== null) {
            $query->where('status', (string) $status);
            $filters['status'] = $status;
        }
        if (($type = $request->get('order_type')) !== null) {
            $query->where('type', (string) $type);
            $filters['order_type'] = $type;
        }
        if (($cashier = trim((string) ($request->get('cashier') ?? ''))) !== '') {
            $ids = ctype_digit($cashier)
                ? [(int) $cashier]
                : User::query()->withoutGlobalScopes()->where('name', 'like', '%'.$cashier.'%')->pluck('id')->all();
            $query->whereIn('user_id', $ids);
            $filters['cashier'] = $cashier;
        }
        if (($customer = $request->get('customer_id')) !== null) {
            $query->where('customer_id', (int) $customer);
            $filters['customer_id'] = (int) $customer;
        }
        if (($min = $request->get('min_total')) !== null) {
            $query->where('total', '>=', (float) $min);
            $filters['min_total'] = (float) $min;
        }
        if (($max = $request->get('max_total')) !== null) {
            $query->where('total', '<=', (float) $max);
            $filters['max_total'] = (float) $max;
        }
        if ($request->get('has_discount') !== null) {
            $request->get('has_discount') ? $query->where('discount', '>', 0) : $query->where(fn ($q) => $q->where('discount', 0)->orWhereNull('discount'));
            $filters['has_discount'] = (bool) $request->get('has_discount');
        }
        if ((bool) $request->get('unpaid')) {
            $query->where('status', OrderStatus::COMPLETED->value)
                ->whereRaw('orders.total - COALESCE((SELECT SUM(amount) FROM payments WHERE payments.order_id = orders.id), 0) > 0.005');
            $filters['unpaid'] = true;
        }

        return $filters;
    }

    /** @return array<string, mixed> */
    private function row(Order $order): array
    {
        return array_filter([
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'created_at' => $order->created_at?->format('Y-m-d H:i'),
            'type' => $order->type,
            'status' => $order->status->value,
            'total' => round((float) $order->total, 2),
            'discount' => (float) $order->discount > 0 ? round((float) $order->discount, 2) : null,
            'cashier' => $this->userName($order->user),
            'customer' => $order->customer?->getAttribute('name'),
            'return_status' => $order->return_status?->value !== 'none' ? $order->return_status?->value : null,
        ], fn ($value) => $value !== null);
    }

    /** @return array<string, mixed> */
    private function detail(Order $order): array
    {
        $paid = (float) $order->payments->sum('amount');
        $customer = $order->customer;

        $detail = [
            ...$this->row($order),
            'customer' => $customer !== null ? ['customer_id' => $customer->getKey(), 'name' => $customer->getAttribute('name'), 'phone' => FindEntitiesTool::maskPhone((string) $customer->getAttribute('phone'))] : null,
            'guests' => $order->guests_count,
            'table' => $order->dine_table_number,
            'sub_total' => round((float) $order->sub_total, 2),
            'discount' => round((float) $order->discount, 2),
            'service' => round((float) $order->service, 2),
            'tax' => round((float) $order->tax, 2),
            'fees' => round((float) $order->fees_total, 2),
            'equipment' => round((float) $order->equipment_total, 2),
            'profit' => round((float) $order->profit, 2),
            'items' => OrderItem::query()->with('product')->where('order_id', $order->id)->get()->map(fn (OrderItem $item) => array_filter([
                'product' => $item->product?->getAttribute('name'),
                'quantity' => (float) $item->quantity,
                'price' => round((float) $item->price, 2),
                'total' => round((float) $item->total, 2),
                'item_discount' => (float) $item->item_discount > 0 ? round((float) $item->item_discount, 2) : null,
                'notes' => $item->notes ?: null,
            ], fn ($v) => $v !== null))->all(),
            'payments' => Payment::query()->where('order_id', $order->id)->get()->map(fn (Payment $p) => ['method' => $p->method->value, 'amount' => round((float) $p->amount, 2), 'shift_id' => $p->shift_id])->all(),
            'paid' => round($paid, 2),
            'unpaid' => $order->status === OrderStatus::COMPLETED ? round(max(0, (float) $order->total - $paid), 2) : null,
            'order_notes' => $order->order_notes ?: null,
            'history' => OrderHistory::query()->with('user')->where('order_id', $order->id)->orderBy('created_at')->limit(30)->get()
                ->map(fn (OrderHistory $h) => array_filter([
                    'at' => $h->created_at?->format('Y-m-d H:i'),
                    'action' => $h->action,
                    'description' => $h->description,
                    'by' => $this->userName($h->user),
                ]))->all(),
            'item_changes' => OrderItemChange::query()->where('order_id', $order->id)->orderBy('created_at')->limit(30)->get()
                ->map(fn (OrderItemChange $c) => [
                    'at' => $c->created_at?->format('Y-m-d H:i'),
                    'product' => $c->product_name,
                    'change' => $c->change_type,
                    'old_quantity' => (float) $c->old_quantity,
                    'new_quantity' => (float) $c->new_quantity,
                    'by' => $this->userName(User::query()->withoutGlobalScopes()->find($c->user_id)),
                ])->all(),
        ];

        if ($this->features->returnsEnabled()) {
            $detail['returns'] = $order->returns()->get()->map(fn ($r) => [
                'at' => $r->getAttribute('created_at')?->format('Y-m-d H:i'),
                'refunded' => round((float) $r->getAttribute('total_refund'), 2),
                'reason' => $r->getAttribute('reason'),
            ])->all();
        }

        return array_filter($detail, fn ($value) => $value !== null && $value !== []);
    }

    private function userName(mixed $user): ?string
    {
        if (! $user instanceof User) {
            return null;
        }

        return $user->isVendor() ? User::VENDOR_DISPLAY_NAME : (string) $user->name;
    }
}
