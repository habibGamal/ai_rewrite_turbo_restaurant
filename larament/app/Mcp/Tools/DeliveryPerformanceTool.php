<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Driver;
use App\Modules\Delivery\Filament\Pages\Reports\DriverPerformanceReport;
use App\Services\ShiftsReportService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('delivery_performance')]
#[Title('Delivery drivers')]
#[Description(<<<'TEXT'
Delivery drivers in a period: orders assigned, completed, completion rate, value delivered and average order value per driver.
Use for "which driver delivered most", "how many deliveries did each driver make", "delivery value per driver".
For delivery sales as a channel use sales_breakdown group_by=channel.
Examples: «كم طلبًا وصّل كل طيار هذا الأسبوع؟», "Top driver by delivered value this month".
TEXT)]
#[IsReadOnly]
class DeliveryPerformanceTool extends BusinessTool
{
    private readonly ShiftsReportService $shifts;

    /** Services come from the container when the tool is built without them. */
    public function __construct(?ShiftsReportService $shifts = null)
    {
        $this->shifts = $shifts ?? app(ShiftsReportService::class);
    }

    protected function reportPages(): array
    {
        return [DriverPerformanceReport::class];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->periodSchema($schema, compare: false),
            'limit' => $schema->integer()->description('Default 10, max 100.'),
        ];
    }

    protected function run(Request $request): array
    {
        $period = $this->period($request, 'this_month', 'none');
        $shiftIds = $this->shifts->getShiftsInPeriodQuery($period->from->toDateString(), $period->to->toDateString(), null)->pluck('id')->all();

        // Same query as the driver report's table.
        $rows = ($shiftIds === [] ? collect() : Driver::query()
            ->select('drivers.id', 'drivers.name')
            ->selectRaw('COUNT(orders.id) as orders_count')
            ->selectRaw("COUNT(CASE WHEN orders.status = 'completed' THEN 1 END) as completed_orders_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN orders.status = 'completed' THEN orders.total ELSE 0 END), 0) as total_value")
            ->selectRaw("AVG(CASE WHEN orders.status = 'completed' THEN orders.total END) as avg_order_value")
            ->join('orders', fn ($join) => $join->on('drivers.id', '=', 'orders.driver_id')->whereIn('orders.shift_id', $shiftIds))
            ->groupBy('drivers.id', 'drivers.name')
            ->orderByDesc('total_value')
            ->get())
            ->take($this->limit($request))
            ->map(fn ($driver) => [
                'driver_id' => (int) $driver->getKey(),
                'driver' => $driver->getAttribute('name'),
                'orders' => (int) $driver->getAttribute('orders_count'),
                'completed' => (int) $driver->getAttribute('completed_orders_count'),
                'completion_rate' => self::share((int) $driver->getAttribute('completed_orders_count'), (int) $driver->getAttribute('orders_count')),
                'value' => round((float) $driver->getAttribute('total_value'), 2),
                'avg_order' => round((float) $driver->getAttribute('avg_order_value'), 2),
            ])->values()->all();

        return $this->envelope($period, ['drivers' => $rows], [
            'value' => 'Total of completed orders the driver delivered.',
            'period' => 'Orders of shifts that started in the period (the driver report\'s rule).',
        ], $rows === [] ? ['No delivery orders with a driver in this period.'] : []);
    }
}
