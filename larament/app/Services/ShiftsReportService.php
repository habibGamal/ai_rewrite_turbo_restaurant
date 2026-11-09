<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shift;
use App\Services\Reports\ReportOrderTypesService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
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
     * Get shifts within a date range or specific shift IDs
     */
    public function getShiftsInPeriodQuery(?string $startDate = null, ?string $endDate = null, ?array $shiftIds = null)
    {
        $query = Shift::query()->with(['orders', 'expenses', 'user']);

        if ($shiftIds && ! empty($shiftIds)) {
            $query->whereIn('id', $shiftIds);
        } else {
            if ($startDate) {
                $query->where('start_at', '>=', Carbon::parse($startDate)->startOfDay());
            }

            if ($endDate) {
                $query->where('start_at', '<=', Carbon::parse($endDate)->endOfDay());
            }
        }

        return $query;
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

        $query = DB::table('shifts')
            ->select([
                DB::raw('COUNT(*) as total_shifts'),
                DB::raw('COUNT(DISTINCT user_id) as distinct_users'),
                DB::raw("{$minutesExpression} as total_minutes"),
            ]);

        if ($shiftIds && ! empty($shiftIds)) {
            $query->whereIn('id', $shiftIds);
        } else {
            $query->whereBetween('created_at', [
                Carbon::parse($startDate)->startOfDay(),
                Carbon::parse($endDate)->endOfDay(),
            ]);
        }

        return $query->first();
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

        // Calculate average receipt value
        $avgReceiptValue = $completedOrders->count() > 0 ? $sales / $completedOrders->count() : 0;

        // Calculate profit percentage
        $profitPercent = $sales > 0 ? ($profit / $sales) * 100 : 0;

        return [
            'sales' => $sales,
            'profit' => $profit,
            'expenses' => $expenses,
            'discounts' => $discounts,
            'cashPayments' => $cashPayments,
            'cardPayments' => $cardPayments,
            'talabatCardPayments' => $talabatCardPayments,
            'avgReceiptValue' => $avgReceiptValue,
            'profitPercent' => $profitPercent,
        ];
    }

    /**
     * Calculate aggregated statistics for multiple shifts
     */
    public function calculatePeriodStats(?string $startDate = null, ?string $endDate = null, ?array $shiftIds = null)
    {
        $shiftsQuery = DB::table('shifts');

        if ($shiftIds && ! empty($shiftIds)) {
            $shiftsQuery->whereIn('shifts.id', $shiftIds);
        } else {
            $shiftsQuery->whereBetween('shifts.created_at', [
                Carbon::parse($startDate)->startOfDay(),
                Carbon::parse($endDate)->endOfDay(),
            ]);
        }

        $ordersData = (clone $shiftsQuery)
            ->select([
                DB::raw('COUNT(orders.id) as total_orders'),
                DB::raw('SUM(orders.total) as sales'),
                DB::raw('SUM(orders.profit) as profit'),
                DB::raw('SUM(orders.discount) as discounts'),
            ])
            ->leftJoin(
                'orders',
                fn ($join) => $join->on('shifts.id', '=', 'orders.shift_id')
                    ->where('orders.status', OrderStatus::COMPLETED)
            )->first();

        $paymentsData = (clone $shiftsQuery)
            ->select([
                DB::raw('SUM(CASE WHEN payments.method = "cash" THEN payments.amount ELSE 0 END) as cash_payments'),
                DB::raw('SUM(CASE WHEN payments.method = "card" THEN payments.amount ELSE 0 END) as card_payments'),
                DB::raw('SUM(CASE WHEN payments.method = "talabat_card" THEN payments.amount ELSE 0 END) as talabat_card_payments'),
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

        $totalStats = [
            'sales' => $ordersData->sales ?? 0,
            'profit' => $ordersData->profit ?? 0,
            'expenses' => $expensesData->expenses ?? 0,
            'discounts' => $ordersData->discounts ?? 0,

            'cashPayments' => $paymentsData->cash_payments ?? 0,
            'cardPayments' => $paymentsData->card_payments ?? 0,
            'talabatCardPayments' => $paymentsData->talabat_card_payments ?? 0,

            'avgReceiptValue' => ($ordersData->total_orders ?? 0) > 0 ? ($ordersData->sales ?? 0) / ($ordersData->total_orders ?? 1) : 0,
            'profitPercent' => ($ordersData->sales ?? 0) > 0 ? (($ordersData->profit ?? 0) / ($ordersData->sales ?? 1)) * 100 : 0,
            'totalOrders' => $ordersData->total_orders ?? 0,
        ];

        return $totalStats;
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

    private function typeStats(\Illuminate\Database\Eloquent\Builder $query): array
    {
        $types = app(ReportOrderTypesService::class)->forQuery($query);
        $data = (clone $query)->selectRaw('orders.type, COUNT(*) as count, SUM(orders.total) as value, SUM(orders.profit) as profit')->groupBy('orders.type')->get()->keyBy('type');

        return array_map(function (array $type) use ($data): array {
            $row = $data->get($type['code']);

            return $type + ['count' => (int) ($row->count ?? 0), 'value' => (float) ($row->value ?? 0), 'profit' => (float) ($row->profit ?? 0)];
        }, $types);
    }

    /**
     * Calculate aggregated order statistics for multiple shifts
     */
    public function calculatePeriodOrderStats(?string $startDate = null, ?string $endDate = null, ?array $shiftIds = null)
    {
        $shiftsQuery = DB::table('shifts');

        if ($shiftIds && ! empty($shiftIds)) {
            $shiftsQuery->whereIn('shifts.id', $shiftIds);
        } else {
            $shiftsQuery->whereBetween('shifts.created_at', [
                Carbon::parse($startDate)->startOfDay(),
                Carbon::parse($endDate)->endOfDay(),
            ]);
        }

        $ordersData = $shiftsQuery
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
        $query = Order::query()->where('orders.status', OrderStatus::COMPLETED);
        if ($shiftIds !== null) {
            $query->whereIn('orders.shift_id', $shiftIds);
        } else {
            $query->whereIn('orders.shift_id', Shift::query()->select('id')->whereBetween('created_at', [Carbon::parse($startDate)->startOfDay(), Carbon::parse($endDate)->endOfDay()]));
        }

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
