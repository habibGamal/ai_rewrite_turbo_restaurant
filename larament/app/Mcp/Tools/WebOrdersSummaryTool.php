<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\OrderBehavior;
use App\Enums\OrderStatus;
use App\Modules\WebOrders\Filament\Pages\Reports\WebOrdersReport;
use App\Orders\OrderTypeCatalog;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('web_orders_summary')]
#[Title('Website orders')]
#[Description(<<<'TEXT'
Orders that came from the shop's website: how many, by status (waiting, in progress, out for delivery, completed, cancelled), completion and cancellation rates, value, and the split between web delivery and web takeaway, with a comparison period.
Use for "how many website orders today", "how many web orders are waiting", "what share of web orders get cancelled".
Examples: «كم طلبًا جاء من الموقع هذا الأسبوع؟», «كم طلب ويب في الانتظار الآن؟», "Web order cancellation rate this month".
TEXT)]
#[IsReadOnly]
class WebOrdersSummaryTool extends BusinessTool
{
    protected function reportPages(): array
    {
        return [WebOrdersReport::class];
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->periodSchema($schema, default: 'today');
    }

    protected function run(Request $request): array
    {
        $period = $this->period($request, 'today');
        $types = app(OrderTypeCatalog::class)->codesWithBehavior([OrderBehavior::WEB_DELIVERY, OrderBehavior::WEB_TAKEAWAY]);
        $current = $this->figures($types, $period->from->toDateString(), $period->to->toDateString());
        $comparison = $period->comparison();
        $previous = $comparison !== null ? $this->figures($types, $comparison->from->toDateString(), $comparison->to->toDateString()) : null;

        return $this->envelope($period, array_filter([
            'current' => $current,
            'previous' => $previous !== null ? ['orders' => $previous['orders'], 'completed_value' => $previous['completed_value'], 'cancel_rate' => $previous['cancel_rate']] : null,
            'change_percent' => $previous !== null ? ['orders' => self::change($current['orders'], $previous['orders']), 'completed_value' => self::change($current['completed_value'], $previous['completed_value'])] : null,
        ]), [
            'completion_rate' => 'completed / all web orders, percent.',
            'cancel_rate' => 'cancelled / all web orders, percent.',
            'open' => 'Waiting, in progress or out for delivery right now.',
        ], $types === [] ? ['This shop has no website order types.'] : [], [], $comparison !== null ? ['from' => $comparison->from->toDateString(), 'to' => $comparison->to->toDateString()] : null);
    }

    /**
     * @param  list<string>  $types
     * @return array<string, mixed>
     */
    private function figures(array $types, string $from, string $to): array
    {
        $rows = DB::table('orders')
            ->whereIn('type', $types === [] ? ['-'] : $types)
            ->whereBetween('created_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])
            ->groupBy('status', 'type')
            ->select('status', 'type')
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(total), 0) as value')
            ->get();

        $byStatus = [];
        foreach (OrderStatus::cases() as $status) {
            $byStatus[$status->value] = (int) $rows->where('status', $status->value)->sum('n');
        }
        $total = array_sum($byStatus);
        $completedValue = (float) $rows->where('status', OrderStatus::COMPLETED->value)->sum('value');

        return [
            'orders' => $total,
            'by_status' => $byStatus,
            'open' => $byStatus['pending'] + $byStatus['processing'] + $byStatus['out_for_delivery'],
            'completed_value' => round($completedValue, 2),
            'avg_completed_order' => $byStatus['completed'] > 0 ? round($completedValue / $byStatus['completed'], 2) : 0.0,
            'completion_rate' => self::share($byStatus['completed'], $total),
            'cancel_rate' => self::share($byStatus['cancelled'], $total),
            'by_type' => $rows->groupBy('type')->map(fn ($group) => (int) $group->sum('n'))->all(),
        ];
    }
}
