<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Modules\Restaurant\Filament\Pages\Reports\KitchenPerformanceReport;
use App\Modules\Restaurant\Services\KitchenPerformanceReportService;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('kitchen_performance')]
#[Title('Kitchen performance')]
#[Description(<<<'TEXT'
Kitchen speed (restaurant shops with the kitchen screen): tickets, average kitchen time, share of late rounds, average prep time per item, by station, by order type, and the slowest products.
Use for "how fast is the kitchen", "which station is slowest", "which dishes take longest", "are delivery orders late".
Examples: «ما متوسط وقت تحضير الطلبات هذا الأسبوع؟», «أي قسم في المطبخ هو الأبطأ؟», "Slowest dishes this month".
TEXT)]
#[IsReadOnly]
class KitchenPerformanceTool extends BusinessTool
{
    private readonly KitchenPerformanceReportService $kitchen;

    /** Services come from the container when the tool is built without them. */
    public function __construct(?KitchenPerformanceReportService $kitchen = null)
    {
        $this->kitchen = $kitchen ?? app(KitchenPerformanceReportService::class);
    }

    protected function reportPages(): array
    {
        return [KitchenPerformanceReport::class];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->periodSchema($schema, compare: false, default: 'last_7_days'),
            'limit' => $schema->integer()->description('Slowest products to list. Default 10, max 100.'),
        ];
    }

    protected function run(Request $request): array
    {
        $period = $this->period($request, 'last_7_days', 'none');
        $start = Carbon::parse($period->from->toDateString())->startOfDay();
        $end = Carbon::parse($period->to->toDateString())->endOfDay();
        $minutes = fn (float $seconds): float => round($seconds / 60, 1);

        $summary = $this->kitchen->getSummary($start, $end);
        $products = collect($this->kitchen->getProductPerformance($start, $end))
            ->sortByDesc('avg_prep_seconds')
            ->take($this->limit($request))
            ->map(fn (array $row) => [
                'product' => $row['product_name'],
                'items' => $row['count'],
                'avg_prep_minutes' => $minutes($row['avg_prep_seconds']),
                'max_prep_minutes' => $minutes($row['max_prep_seconds']),
                'late_percent' => round($row['late_items_percent'], 1),
            ])->values()->all();

        return $this->envelope($period, [
            'summary' => [
                'tickets' => $summary['tickets_count'],
                'avg_kitchen_minutes' => $minutes($summary['avg_kitchen_seconds']),
                'late_rounds_percent' => round($summary['late_rounds_percent'], 1),
                'avg_item_prep_minutes' => $minutes($summary['avg_item_prep_seconds']),
                'voided_after_sending' => $summary['waste_candidates_count'],
            ],
            'stations' => array_map(fn (array $row) => ['station' => $row['station_name'], 'items' => $row['count'], 'avg_prep_minutes' => $minutes($row['avg_prep_seconds'])], $this->kitchen->getStationPerformance($start, $end)),
            'order_types' => array_map(fn (array $row) => ['order_type' => $row['label'], 'tickets' => $row['count'], 'avg_kitchen_minutes' => $minutes($row['avg_kitchen_seconds']), 'late_rounds_percent' => round($row['late_rounds_percent'], 1)], $this->kitchen->getOrderTypePerformance($start, $end)),
            'slowest_products' => $products,
        ], [
            'avg_kitchen_minutes' => 'From sending a round to the kitchen until it is marked ready, averaged per ticket.',
            'late_rounds_percent' => 'Rounds that took longer than their target time.',
            'voided_after_sending' => 'Items voided after they were sent to the kitchen (possible waste).',
        ]);
    }
}
