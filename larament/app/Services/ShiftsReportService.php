<?php

namespace App\Services;

use App\Models\Shift;
use App\Models\Order;
use App\Models\ExpenceType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Collection;
use Carbon\Carbon;

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
     * Get shifts within a date range
     */
    public function getShiftsInPeriod(?string $startDate = null, ?string $endDate = null): Collection
    {
        $query = Shift::query()->with(['orders', 'expenses', 'user']);

        if ($startDate) {
            $query->where('start_at', '>=', Carbon::parse($startDate)->startOfDay());
        }

        if ($endDate) {
            $query->where('start_at', '<=', Carbon::parse($endDate)->endOfDay());
        }

        return $query->orderBy('start_at', 'desc')->get();
    }

    /**
     * Calculate shift financial statistics
     */
    public function calculateShiftStats(Shift $shift): array
    {
        // Get completed orders for this shift
        $completedOrders = $shift->orders()
            ->where('status', OrderStatus::COMPLETED)
            ->with('payments')
            ->get();

        $sales = $completedOrders->sum('total');
        $profit = $completedOrders->sum('profit');
        $discounts = $completedOrders->sum('discount');

        // Calculate payment method totals
        $cashPayments = 0;
        $cardPayments = 0;
        $talabatCardPayments = 0;

        foreach ($completedOrders as $order) {
            foreach ($order->payments as $payment) {
                if ($payment->method === PaymentMethod::CASH) {
                    $cashPayments += $payment->amount;
                } elseif ($payment->method === PaymentMethod::CARD) {
                    $cardPayments += $payment->amount;
                } elseif ($payment->method === PaymentMethod::TALABAT_CARD) {
                    $talabatCardPayments += $payment->amount;
                }
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
    public function calculatePeriodStats(Collection $shifts): array
    {
        $totalStats = [
            'sales' => 0,
            'profit' => 0,
            'expenses' => 0,
            'discounts' => 0,
            'cashPayments' => 0,
            'cardPayments' => 0,
            'talabatCardPayments' => 0,
            'avgReceiptValue' => 0,
            'profitPercent' => 0,
            'totalOrders' => 0,
        ];

        $totalOrderCount = 0;

        foreach ($shifts as $shift) {
            $shiftStats = $this->calculateShiftStats($shift);

            $totalStats['sales'] += $shiftStats['sales'];
            $totalStats['profit'] += $shiftStats['profit'];
            $totalStats['expenses'] += $shiftStats['expenses'];
            $totalStats['discounts'] += $shiftStats['discounts'];
            $totalStats['cashPayments'] += $shiftStats['cashPayments'];
            $totalStats['cardPayments'] += $shiftStats['cardPayments'];
            $totalStats['talabatCardPayments'] += $shiftStats['talabatCardPayments'];

            // Count completed orders for this shift
            $shiftOrderCount = $shift->orders()->where('status', OrderStatus::COMPLETED)->count();
            $totalOrderCount += $shiftOrderCount;
        }

        // Calculate averages
        $totalStats['avgReceiptValue'] = $totalOrderCount > 0 ? $totalStats['sales'] / $totalOrderCount : 0;
        $totalStats['profitPercent'] = $totalStats['sales'] > 0 ? ($totalStats['profit'] / $totalStats['sales']) * 100 : 0;
        $totalStats['totalOrders'] = $totalOrderCount;

        return $totalStats;
    }

    /**
     * Calculate order statistics by status for a shift
     */
    public function calculateOrderStats(Shift $shift): array
    {
        $orders = $shift->orders();

        $stats = [];
        foreach (OrderStatus::cases() as $status) {
            $statusOrders = $orders->where('status', $status)->get();
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
        $completedOrders = $shift->orders()
            ->where('status', OrderStatus::COMPLETED)
            ->get();

        $stats = [
            'dineIn' => ['count' => 0, 'value' => 0, 'profit' => 0],
            'delivery' => ['count' => 0, 'value' => 0, 'profit' => 0],
            'takeaway' => ['count' => 0, 'value' => 0, 'profit' => 0],
            'talabat' => ['count' => 0, 'value' => 0, 'profit' => 0],
            'webDelivery' => ['count' => 0, 'value' => 0, 'profit' => 0],
            'webTakeaway' => ['count' => 0, 'value' => 0, 'profit' => 0],
            'companies' => ['count' => 0, 'value' => 0, 'profit' => 0],
        ];

        foreach ($completedOrders as $order) {
            $key = $this->getStatsKey($order->type);
            if ($key) {
                $stats[$key]['count']++;
                $stats[$key]['value'] += $order->total;
                $stats[$key]['profit'] += $order->profit;
            }
        }

        return $stats;
    }

    /**
     * Helper method to get stats key from order type
     */
    private function getStatsKey(OrderType $orderType): ?string
    {
        return match ($orderType) {
            OrderType::DINE_IN => 'dineIn',
            OrderType::DELIVERY => 'delivery',
            OrderType::TAKEAWAY => 'takeaway',
            OrderType::TALABAT => 'talabat',
            OrderType::WEB_DELIVERY => 'webDelivery',
            OrderType::WEB_TAKEAWAY => 'webTakeaway',
            OrderType::COMPANIES => 'companies',
            default => null,
        };
    }

    /**
     * Calculate aggregated order statistics for multiple shifts
     */
    public function calculatePeriodOrderStats(Collection $shifts): array
    {
        $totalStats = [];

        // Initialize stats for all statuses
        foreach (OrderStatus::cases() as $status) {
            $totalStats[$status->value] = [
                'count' => 0,
                'value' => 0,
                'profit' => 0,
            ];
        }

        foreach ($shifts as $shift) {
            $shiftStats = $this->calculateOrderStats($shift);

            foreach ($shiftStats as $status => $stats) {
                $totalStats[$status]['count'] += $stats['count'];
                $totalStats[$status]['value'] += $stats['value'];
                $totalStats[$status]['profit'] += $stats['profit'];
            }
        }

        return $totalStats;
    }

    /**
     * Calculate aggregated order type statistics for multiple shifts
     */
    public function calculatePeriodOrderTypeStats(Collection $shifts): array
    {
        $totalStats = [];

        // Initialize stats for all types using enum values
        foreach (OrderType::cases() as $type) {
            $totalStats[$type->value] = [
                'count' => 0,
                'value' => 0,
                'profit' => 0,
            ];
        }

        // Convert legacy format to enum value format
        $enumValueMapping = [
            'dineIn' => 'dine_in',
            'delivery' => 'delivery',
            'takeaway' => 'takeaway',
            'talabat' => 'talabat',
            'webDelivery' => 'web_delivery',
            'webTakeaway' => 'web_takeaway',
            'companies' => 'companies',
        ];

        foreach ($shifts as $shift) {
            $shiftStats = $this->calculateOrderTypeStats($shift);

            foreach ($shiftStats as $legacyKey => $stats) {
                $enumValue = $enumValueMapping[$legacyKey] ?? $legacyKey;
                if (isset($totalStats[$enumValue])) {
                    $totalStats[$enumValue]['count'] += $stats['count'];
                    $totalStats[$enumValue]['value'] += $stats['value'];
                    $totalStats[$enumValue]['profit'] += $stats['profit'];
                }
            }
        }

        return $totalStats;
    }

    /**
     * Get orders query for shifts
     */
    public function getOrdersQueryForShifts(Collection $shifts)
    {
        if ($shifts->isEmpty()) {
            return Order::query()->where('id', 0); // Empty query
        }

        $shiftIds = $shifts->pluck('id')->toArray();

        return Order::query()
            ->whereIn('shift_id', $shiftIds)
            ->with(['customer', 'user', 'payments', 'shift'])
            ->latest();
    }

    /**
     * Get expenses query for shifts with aggregation by expense type
     */
    public function getExpensesQueryForShifts(Collection $shifts)
    {
        if ($shifts->isEmpty()) {
            return ExpenceType::query()->where('id', 0); // Empty query
        }

        $shiftIds = $shifts->pluck('id')->toArray();

        return ExpenceType::query()
            ->select([
                'expence_types.id',
                'expence_types.name',
                DB::raw('COUNT(expenses.id) as expense_count'),
                DB::raw('COALESCE(SUM(expenses.amount), 0) as total_amount'),
            ])
            ->leftJoin('expenses', function($join) use ($shiftIds) {
                $join->on('expence_types.id', '=', 'expenses.expence_type_id')
                     ->whereIn('expenses.shift_id', $shiftIds);
            })
            ->groupBy('expence_types.id', 'expence_types.name')
            ->havingRaw('COUNT(expenses.id) > 0'); // Only show types that have expenses
    }

    /**
     * Get period info for display
     */
    public function getPeriodInfo(?string $startDate = null, ?string $endDate = null): array
    {
        if (!$startDate && !$endDate) {
            return [
                'title' => 'جميع الشفتات',
                'description' => 'تقرير شامل لجميع الشفتات',
            ];
        }

        $start = $startDate ? Carbon::parse($startDate) : null;
        $end = $endDate ? Carbon::parse($endDate) : null;

        if ($start && $end) {
            return [
                'title' => 'تقرير الفترة',
                'description' => sprintf(
                    'من %s إلى %s',
                    $start->format('d/m/Y'),
                    $end->format('d/m/Y')
                ),
            ];
        } elseif ($start) {
            return [
                'title' => 'تقرير من تاريخ محدد',
                'description' => sprintf('من %s حتى الآن', $start->format('d/m/Y')),
            ];
        } else {
            return [
                'title' => 'تقرير حتى تاريخ محدد',
                'description' => sprintf('حتى %s', $end->format('d/m/Y')),
            ];
        }
    }
}
