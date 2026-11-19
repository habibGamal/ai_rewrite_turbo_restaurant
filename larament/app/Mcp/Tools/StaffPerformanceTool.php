<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\OrderStatus;
use App\Filament\Pages\Reports\CashierCommissionReport;
use App\Models\User;
use App\Services\CashierCommissionReportService;
use App\Services\FeatureService;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('staff_performance')]
#[Title('Staff performance')]
#[Description(<<<'TEXT'
Per cashier/staff member for a period: completed orders and sales, average order, discounts given (count, amount, rate), cancelled orders, items added or removed on saved orders, and refunds they made.
Use for "who sold the most", "which cashier gives the most discounts", "who cancels many orders", "items removed after printing".
Not for one order's history (use orders) or the event log (use audit_events).
Examples: «أي كاشير أعطى أكبر خصومات هذا الشهر؟», «من ألغى أكثر الطلبات هذا الأسبوع؟», "Sales per cashier yesterday".
TEXT)]
#[IsReadOnly]
class StaffPerformanceTool extends BusinessTool
{
    private readonly CashierCommissionReportService $commissions;

    private readonly FeatureService $features;

    /** Services come from the container when the tool is built without them. */
    public function __construct(?CashierCommissionReportService $commissions = null, ?FeatureService $features = null)
    {
        $this->commissions = $commissions ?? app(CashierCommissionReportService::class);
        $this->features = $features ?? app(FeatureService::class);
    }

    protected function reportPages(): array
    {
        return [CashierCommissionReport::class];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->periodSchema($schema, compare: false),
            'staff' => $schema->string()->description('Only this staff member (name or id). Default: everyone.'),
            'rank_by' => $schema->string()->enum(['net_sales', 'orders', 'discount', 'cancelled', 'items_removed'])->description('Default net_sales.'),
            'limit' => $schema->integer()->description('Default 10, max 100.'),
        ];
    }

    protected function run(Request $request): array
    {
        $period = $this->period($request, 'this_month', 'none');
        $start = Carbon::parse($period->from->toDateString())->startOfDay();
        $end = Carbon::parse($period->to->toDateString())->endOfDay();

        $orders = DB::table('orders')
            ->whereBetween('created_at', [$start, $end])
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->select('user_id')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as completed', [OrderStatus::COMPLETED->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN total ELSE 0 END) as sales', [OrderStatus::COMPLETED->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN sub_total ELSE 0 END) as items_value', [OrderStatus::COMPLETED->value])
            ->selectRaw('SUM(CASE WHEN status = ? AND discount > 0 THEN 1 ELSE 0 END) as discounted', [OrderStatus::COMPLETED->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN discount ELSE 0 END) as discount', [OrderStatus::COMPLETED->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as cancelled', [OrderStatus::CANCELLED->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN total ELSE 0 END) as cancelled_value', [OrderStatus::CANCELLED->value])
            ->get()
            ->keyBy('user_id');

        $changes = $this->commissions->getCashierCommissionQuery([], $start, $end)->get()->keyBy('id');

        $refunds = $this->features->returnsEnabled()
            ? DB::table('order_returns')->whereBetween('created_at', [$start, $end])->groupBy('user_id')
                ->select('user_id')->selectRaw('COUNT(*) as n, SUM(total_refund) as amount')->get()->keyBy('user_id')
            : collect();

        $ids = $orders->keys()->merge($changes->keys())->merge($refunds->keys())->unique()->filter()->values();
        $users = User::query()->withoutGlobalScopes()->whereIn('id', $ids)->get()->keyBy('id');

        $staff = trim((string) ($request->get('staff') ?? ''));
        $rows = [];
        foreach ($ids as $id) {
            $user = $users->get($id);
            $name = $user === null ? (string) $id : ($user->isVendor() ? User::VENDOR_DISPLAY_NAME : (string) $user->name);
            if ($staff !== '' && (string) $id !== $staff && mb_stripos($name, $staff) === false) {
                continue;
            }

            $o = $orders->get($id);
            $c = $changes->get($id);
            $r = $refunds->get($id);
            $completed = (int) ($o->completed ?? 0);
            $sales = (float) ($o->sales ?? 0);
            $discount = (float) ($o->discount ?? 0);
            $cancelled = (int) ($o->cancelled ?? 0);

            $row = [
                'staff_id' => (int) $id,
                'name' => $name,
                'orders' => $completed,
                'sales' => round($sales, 2),
                'avg_order' => $completed > 0 ? round($sales / $completed, 2) : 0.0,
                'discounted_orders' => (int) ($o->discounted ?? 0),
                'discount' => round($discount, 2),
                'discount_rate' => self::share($discount, (float) ($o->items_value ?? 0)),
                'cancelled' => $cancelled,
                'cancelled_value' => round((float) ($o->cancelled_value ?? 0), 2),
                'cancel_rate' => self::share($cancelled, $completed + $cancelled),
                'items_added' => (float) ($c?->getAttribute('items_added_count') ?? 0),
                'items_removed' => (float) ($c?->getAttribute('items_removed_count') ?? 0),
                'items_removed_value' => round((float) ($c?->getAttribute('items_removed_value') ?? 0), 2),
            ];
            if ($this->features->returnsEnabled()) {
                $row['refunds'] = (int) ($r->n ?? 0);
                $row['refunded'] = round((float) ($r->amount ?? 0), 2);
            }
            $rows[] = $row;
        }

        $rank = match ((string) ($request->get('rank_by') ?? 'net_sales')) {
            'orders' => 'orders',
            'discount' => 'discount',
            'cancelled' => 'cancelled',
            'items_removed' => 'items_removed',
            default => 'sales',
        };
        usort($rows, fn ($a, $b) => $b[$rank] <=> $a[$rank]);

        return $this->envelope(
            $period,
            ['rank_by' => $rank, 'staff' => array_slice($rows, 0, $this->limit($request))],
            [
                'staff' => 'The user who created the order (or made the change/refund).',
                'sales' => 'Total of completed orders they created (before refunds).',
                'discount_rate' => 'discount / item value of their completed orders, percent.',
                'cancel_rate' => 'cancelled / (completed + cancelled), percent.',
                'items_removed' => 'Item quantity removed from orders after they were first saved (sent to the kitchen or printed).',
            ],
            $rows === [] ? ['No staff activity in this period'.($staff !== '' ? " for \"{$staff}\"" : '').'.'] : [],
            $staff === '' ? [] : ['staff' => $staff],
        );
    }
}
