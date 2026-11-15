<?php

namespace App\Services;

use App\Core\Orders\GuestStats;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shift;
use App\Services\Reports\ReportOrderTypesService;
use App\Services\Reports\ReturnsAggregates;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class ShiftsReportService
{
    /**
     * Get current active shift
     */
    public function getCurrentShift(): ?Shift
    {
        return Shift::where('closed', false)
            ->where('end_at', null)
            ->with(['orders', 'expenses', 'user'])
            ->first();
    }

    /**
     * The one rule every period widget uses to pick its shifts: the selected shift IDs when
     * there are any, otherwise shifts that started between the two dates (whole days), otherwise all.
     *
     * @param  array<int|string>|null  $shiftIds
     */
    public function shiftIdsQuery(?string $startDate = null, ?string $endDate = null, ?array $shiftIds = null): QueryBuilder
    {
        $query = DB::table('shifts')->select('shifts.id');

        if ($shiftIds !== null && $shiftIds !== []) {
            return $query->whereIn('shifts.id', $shiftIds);
        }

        if ($startDate) {
            $query->where('shifts.start_at', '>=', Carbon::parse($startDate)->startOfDay());
        }

        if ($endDate) {
            $query->where('shifts.start_at', '<=', Carbon::parse($endDate)->endOfDay());
        }

        return $query;
    }

    /**
     * Normalises a period page's filters into [startDate, endDate, shiftIds].
     *
     * @param  array<string, mixed>|null  $filters
     * @return array{0: ?string, 1: ?string, 2: ?array<int|string>}
     */
    public function scopeFromFilters(?array $filters): array
    {
        $filters ??= [];

        if (($filters['filterType'] ?? 'period') === 'shifts') {
            return [null, null, array_values((array) ($filters['shifts'] ?? []))];
        }

        return [
            $filters['startDate'] ?? now()->subDays(6)->startOfDay()->toDateString(),
            $filters['endDate'] ?? now()->endOfDay()->toDateString(),
            null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $filters
     */
    public function shiftIdsQueryFromFilters(?array $filters): QueryBuilder
    {
        return $this->shiftIdsQuery(...$this->scopeFromFilters($filters));
    }

    /**
     * Get shifts within a date range or specific shift IDs
     */
    public function getShiftsInPeriodQuery(?string $startDate = null, ?string $endDate = null, ?array $shiftIds = null)
    {
        return Shift::query()
            ->with(['orders', 'expenses', 'user'])
            ->whereIn('id', $this->shiftIdsQuery($startDate, $endDate, $shiftIds));
    }

    /**
     * Get shifts count within a date range or specific shift IDs
     */
    public function getShiftsCountInPeriod(?string $startDate = null, ?string $endDate = null, ?array $shiftIds = null)
    {
        return $this->getShiftsInPeriodQuery($startDate, $endDate, $shiftIds)->count();
    }

    /**
     * Get shifts info within a date range or specific shift IDs
     */
    public function getShiftsInfo(?string $startDate = null, ?string $endDate = null, ?array $shiftIds = null)
    {
        $driver = config('database.default');
        $connection = config("database.connections.{$driver}.driver");

        // Use different SQL for different database drivers
        if ($connection === 'sqlite') {
            $minutesExpression = 'SUM((julianday(end_at) - julianday(start_at)) * 24 * 60)';
        } else {
            $minutesExpression = 'SUM(TIMESTAMPDIFF(MINUTE, start_at, end_at))';
        }

        return DB::table('shifts')
            ->select([
                DB::raw('COUNT(*) as total_shifts'),
                DB::raw('COUNT(DISTINCT user_id) as distinct_users'),
                DB::raw("{$minutesExpression} as total_minutes"),
            ])
            ->whereIn('id', $this->shiftIdsQuery($startDate, $endDate, $shiftIds))
            ->first();
    }

    /**
     * Get shifts within a date range or specific shift IDs
     */
    public function getShiftsInPeriod(?string $startDate = null, ?string $endDate = null, ?array $shiftIds = null): Collection
    {
        return $this->getShiftsInPeriodQuery($startDate, $endDate, $shiftIds)
            ->orderBy('start_at', 'desc')
            ->get();
    }

    /**
     * Calculate shift financial statistics
     */
    public function calculateShiftStats(Shift $shift): array
    {
        // Get completed orders for this shift
        $completedOrders = $shift->orders()
            ->where('status', OrderStatus::COMPLETED)
            ->get();

        $sales = $completedOrders->sum('total');
        $profit = $completedOrders->sum('profit');
        $discounts = $completedOrders->sum('discount');
        $fees = (float) $completedOrders->sum('fees_total');

        // Payments belong to the shift that took the money, not the order's shift.
        $cashPayments = 0;
        $cardPayments = 0;
        $talabatCardPayments = 0;

        foreach (Payment::query()->where('shift_id', $shift->id)->get() as $payment) {
            if ($payment->method === PaymentMethod::CASH) {
                $cashPayments += $payment->amount;
            } elseif ($payment->method === PaymentMethod::CARD) {
                $cardPayments += $payment->amount;
            } elseif ($payment->method === PaymentMethod::TALABAT_CARD) {
                $talabatCardPayments += $payment->amount;
            }
        }

        // Calculate expenses
        $expenses = $shift->expenses()->sum('amount');
        $externalCash = (float) DB::table('external_order_collections')->where('shift_id', $shift->id)->where('method', 'cash')->sum('amount');
        $settlements = (float) DB::table('external_seller_settlements')->where('shift_id', $shift->id)->sum('amount');
        $revenues = $this->revenueTotals([$shift->id]);

        // Refunds paid by this shift, whatever shift sold the order.
        $returns = app(ReturnsAggregates::class)->forShifts([$shift->id]);

        // Calculate average receipt value
        $avgReceiptValue = $completedOrders->count() > 0 ? $sales / $completedOrders->count() : 0;

        // Calculate profit percentage
        $profitPercent = $sales > 0 ? ($profit / $sales) * 100 : 0;

        return [
            'sales' => $sales,
            'profit' => $profit,
            'expenses' => $expenses,
            'discounts' => $discounts,
            'fees' => $fees,
            'cashPayments' => $cashPayments,
            'externalCash' => $externalCash,
            'settlements' => $settlements,
            'availableCash' => (float) $shift->start_cash + $cashPayments + $externalCash + $revenues['cashRevenues'] - $settlements - $expenses - $returns['cashRefunds'],
            'cardPayments' => $cardPayments,
            'talabatCardPayments' => $talabatCardPayments,
            'avgReceiptValue' => $avgReceiptValue,
            'profitPercent' => $profitPercent,
        ] + $this->netOfReturns((float) $sales, (float) $profit, $returns) + $returns + $revenues + $this->creditReconciliation([$shift->id]);
    }

    /**
     * Pay-later reconciliation for a set of shifts, so that
     *   sales − uncollectedCredit + previousCollections = money received (cash + card + talabat card).
     *
     * - uncollectedCredit: for completed orders of these shifts, total minus what was paid in these shifts.
     * - previousCollections: payments taken in these shifts for orders that belong to other shifts.
     *
     * @param  QueryBuilder|array<int|string>  $shiftIds
     * @return array{uncollectedCredit: float, uncollectedCreditCount: int, previousCollections: float, previousCollectionsCount: int}
     */
    public function creditReconciliation(QueryBuilder|array $shiftIds): array
    {
        $paidInScope = DB::table('payments')
            ->select('order_id')
            ->selectRaw('SUM(amount) as paid')
            ->whereIn('shift_id', $this->cloneScope($shiftIds))
            ->groupBy('order_id');

        $uncollected = DB::table('orders')
            ->leftJoinSub($paidInScope, 'scope_payments', 'scope_payments.order_id', '=', 'orders.id')
            ->whereIn('orders.shift_id', $this->cloneScope($shiftIds))
            ->where('orders.status', OrderStatus::COMPLETED->value)
            ->whereRaw('orders.total - COALESCE(scope_payments.paid, 0) > 0.005')
            ->selectRaw('COUNT(*) as orders_count, COALESCE(SUM(orders.total - COALESCE(scope_payments.paid, 0)), 0) as amount')
            ->first();

        $collections = DB::table('payments')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->whereIn('payments.shift_id', $this->cloneScope($shiftIds))
            ->whereNotIn('orders.shift_id', $this->cloneScope($shiftIds))
            ->selectRaw('COUNT(DISTINCT payments.order_id) as orders_count, COALESCE(SUM(payments.amount), 0) as amount')
            ->first();

        return [
            'uncollectedCredit' => round((float) ($uncollected->amount ?? 0), 2),
            'uncollectedCreditCount' => (int) ($uncollected->orders_count ?? 0),
            'previousCollections' => round((float) ($collections->amount ?? 0), 2),
            'previousCollectionsCount' => (int) ($collections->orders_count ?? 0),
        ];
    }

    /**
     * Misc shift revenues (rows of the revenues table, never orders) for a set of
     * shifts. Cash revenues are in the drawer, so they count in availableCash;
     * card revenues are reported next to card payments.
     *
     * @param  QueryBuilder|array<int|string>  $shiftIds
     * @return array{revenues: float, cashRevenues: float, cardRevenues: float}
     */
    public function revenueTotals(QueryBuilder|array $shiftIds): array
    {
        $row = DB::table('revenues')
            ->whereIn('shift_id', $this->cloneScope($shiftIds))
            ->selectRaw("COALESCE(SUM(CASE WHEN method = 'cash' THEN amount ELSE 0 END), 0) as cash_amount")
            ->selectRaw("COALESCE(SUM(CASE WHEN method = 'card' THEN amount ELSE 0 END), 0) as card_amount")
            ->first();

        $cash = round((float) ($row->cash_amount ?? 0), 2);
        $card = round((float) ($row->card_amount ?? 0), 2);

        return [
            'revenues' => round($cash + $card, 2),
            'cashRevenues' => $cash,
            'cardRevenues' => $card,
        ];
    }

    /**
     * @param  QueryBuilder|array<int|string>  $shiftIds
     * @return QueryBuilder|array<int|string>
     */
    private function cloneScope(QueryBuilder|array $shiftIds): QueryBuilder|array
    {
        return $shiftIds instanceof QueryBuilder ? clone $shiftIds : $shiftIds;
    }

    /**
     * Calculate aggregated statistics for multiple shifts
     */
    public function calculatePeriodStats(?string $startDate = null, ?string $endDate = null, ?array $shiftIds = null)
    {
        $scope = $this->shiftIdsQuery($startDate, $endDate, $shiftIds);
        $shiftsQuery = DB::table('shifts')->whereIn('shifts.id', $scope);

        $ordersData = (clone $shiftsQuery)
            ->select([
                DB::raw('COUNT(orders.id) as total_orders'),
                DB::raw('SUM(orders.total) as sales'),
                DB::raw('SUM(orders.profit) as profit'),
                DB::raw('SUM(orders.discount) as discounts'),
                DB::raw('SUM(orders.fees_total) as fees'),
            ])
            ->leftJoin(
                'orders',
                fn ($join) => $join->on('shifts.id', '=', 'orders.shift_id')
                    ->where('orders.status', OrderStatus::COMPLETED)
            )->first();

        $paymentsData = (clone $shiftsQuery)
            ->select([
                DB::raw("SUM(CASE WHEN payments.method = 'cash' THEN payments.amount ELSE 0 END) as cash_payments"),
                DB::raw("SUM(CASE WHEN payments.method = 'card' THEN payments.amount ELSE 0 END) as card_payments"),
                DB::raw("SUM(CASE WHEN payments.method = 'talabat_card' THEN payments.amount ELSE 0 END) as talabat_card_payments"),
            ])
            ->leftJoin(
                'payments',
                'shifts.id',
                '=',
                'payments.shift_id'
            )->first();

        $expensesData = (clone $shiftsQuery)
            ->select([
                DB::raw('SUM(expenses.amount) as expenses'),
            ])
            ->leftJoin(
                'expenses',
                'shifts.id',
                '=',
                'expenses.shift_id'
            )->first();

        $externalCash = (float) DB::table('external_order_collections')->whereIn('shift_id', clone $scope)->where('method', 'cash')->sum('amount');
        $settlements = (float) DB::table('external_seller_settlements')->whereIn('shift_id', clone $scope)->sum('amount');
        $startCash = (float) (clone $shiftsQuery)->sum('shifts.start_cash');
        $returns = app(ReturnsAggregates::class)->forShifts(clone $scope);
        $revenues = $this->revenueTotals($scope);
        $totalStats = [
            'sales' => $ordersData->sales ?? 0,
            'profit' => $ordersData->profit ?? 0,
            'expenses' => $expensesData->expenses ?? 0,
            'discounts' => $ordersData->discounts ?? 0,
            'fees' => (float) ($ordersData->fees ?? 0),

            'cashPayments' => $paymentsData->cash_payments ?? 0,
            'externalCash' => $externalCash,
            'settlements' => $settlements,
            'availableCash' => $startCash + (float) ($paymentsData->cash_payments ?? 0) + $externalCash + $revenues['cashRevenues'] - $settlements - (float) ($expensesData->expenses ?? 0) - $returns['cashRefunds'],
            'cardPayments' => $paymentsData->card_payments ?? 0,
            'talabatCardPayments' => $paymentsData->talabat_card_payments ?? 0,

            'avgReceiptValue' => ($ordersData->total_orders ?? 0) > 0 ? ($ordersData->sales ?? 0) / ($ordersData->total_orders ?? 1) : 0,
            'profitPercent' => ($ordersData->sales ?? 0) > 0 ? (($ordersData->profit ?? 0) / ($ordersData->sales ?? 1)) * 100 : 0,
            'totalOrders' => $ordersData->total_orders ?? 0,
        ];

        return $totalStats
            + $this->netOfReturns((float) ($ordersData->sales ?? 0), (float) ($ordersData->profit ?? 0), $returns)
            + $returns
            + $revenues
            + $this->creditReconciliation($scope);
    }

    /**
     * Gross sales and profit minus the refunds paid in the same scope.
     *
     * @param  array{returnsAmount: float, returnsProfitImpact: float}  $returns
     * @return array{netSales: float, netProfit: float}
     */
    private function netOfReturns(float $sales, float $profit, array $returns): array
    {
        return [
            'netSales' => round($sales - $returns['returnsAmount'], 2),
            'netProfit' => round($profit - $returns['returnsProfitImpact'], 2),
        ];
    }

    /**
     * Calculate order statistics by status for a shift
     */
    public function calculateOrderStats(Shift $shift): array
    {
        $orders = $shift->orders()->get();

        $stats = [];
        foreach (OrderStatus::cases() as $status) {
            $statusOrders = $orders->where('status', $status);
            $stats[$status->value] = [
                'count' => $statusOrders->count(),
                'value' => $statusOrders->sum('total'),
                'profit' => $statusOrders->sum('profit'),
            ];
        }

        return $stats;
    }

    /**
     * Calculate order statistics by type for completed orders in a shift
     */
    public function calculateOrderTypeStats(Shift $shift): array
    {
        $query = Order::query()->where('orders.shift_id', $shift->id)->where('orders.status', OrderStatus::COMPLETED);

        return $this->typeStats($query);
    }

    private function typeStats(Builder $query): array
    {
        $types = app(ReportOrderTypesService::class)->forQuery($query);
        $data = (clone $query)->selectRaw(
            'orders.type, COUNT(*) as count, SUM(orders.total) as value, SUM(orders.profit) as profit,'
            .' SUM(CASE WHEN orders.guests_count > 0 THEN orders.guests_count ELSE 0 END) as guests,'
            .' SUM(CASE WHEN orders.guests_count > 0 THEN orders.total ELSE 0 END) as guest_sales'
        )->groupBy('orders.type')->get()->keyBy('type');

        return array_map(function (array $type) use ($data): array {
            $row = $data->get($type['code']);
            $guests = new GuestStats((int) ($row->guests ?? 0), (float) ($row->guest_sales ?? 0));

            return $type + [
                'count' => (int) ($row->count ?? 0),
                'value' => (float) ($row->value ?? 0),
                'profit' => (float) ($row->profit ?? 0),
                'guests' => $guests->guests,
                'guest_sales' => $guests->sales,
                'avg_per_guest' => $guests->averagePerGuest(),
                'guests_summary' => $guests->summary(),
            ];
        }, $types);
    }

    /**
     * Calculate aggregated order statistics for multiple shifts
     */
    public function calculatePeriodOrderStats(?string $startDate = null, ?string $endDate = null, ?array $shiftIds = null)
    {
        $ordersData = DB::table('shifts')
            ->whereIn('shifts.id', $this->shiftIdsQuery($startDate, $endDate, $shiftIds))
            ->select([
                'orders.status',
                DB::raw('COUNT(orders.id) as total_orders'),
                DB::raw('SUM(orders.total) as sales'),
                DB::raw('SUM(orders.profit) as profit'),
            ])
            ->leftJoin(
                'orders',
                fn ($join) => $join->on('shifts.id', '=', 'orders.shift_id')
            )
            ->groupBy('orders.status')
            ->get();

        $totalStats = [];

        // Initialize stats for all statuses
        foreach (OrderStatus::cases() as $status) {
            $totalStats[$status->value] = [
                'count' => $ordersData->where('status', $status->value)->first()->total_orders ?? 0,
                'value' => $ordersData->where('status', $status->value)->first()->sales ?? 0,
                'profit' => $ordersData->where('status', $status->value)->first()->profit ?? 0,
            ];
        }

        return $totalStats;
    }

    /**
     * Calculate aggregated order type statistics for multiple shifts
     */
    public function calculatePeriodOrderTypeStats(?string $startDate = null, ?string $endDate = null, ?array $shiftIds = null)
    {
        $query = Order::query()
            ->where('orders.status', OrderStatus::COMPLETED)
            ->whereIn('orders.shift_id', $this->shiftIdsQuery($startDate, $endDate, $shiftIds));

        return $this->typeStats($query);
    }

    /**
     * Get period info for display
     */
    public function getPeriodInfo(?string $startDate = null, ?string $endDate = null, ?array $shiftIds = null): array
    {
        if ($shiftIds && ! empty($shiftIds)) {
            $count = count($shiftIds);

            return [
                'title' => 'تقرير الشفتات المحددة',
                'description' => sprintf('تقرير لعدد %d شفت محدد', $count),
            ];
        }

        if (! $startDate && ! $endDate) {
            return [
                'title' => 'جميع الشفتات',
                'description' => 'تقرير شامل لجميع الشفتات',
            ];
        }

        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();
        if ($start && $end) {
            return [
                'title' => 'تقرير الفترة',
                'description' => sprintf(
                    'من %s إلى %s',
                    $start->format('d/m/Y h:i A'),
                    $end->format('d/m/Y h:i A')
                ),
            ];
        } elseif ($start) {
            return [
                'title' => 'تقرير من تاريخ محدد',
                'description' => sprintf('من %s حتى الآن', $start->format('d/m/Y h:i A')),
            ];
        } else {
            return [
                'title' => 'تقرير حتى تاريخ محدد',
                'description' => sprintf('حتى %s', $end->format('d/m/Y h:i A')),
            ];
        }
    }
}
