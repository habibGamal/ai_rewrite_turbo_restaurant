<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Modules\Manufacturing\Filament\Pages\Reports\ManufacturingYieldReport;
use App\Modules\Manufacturing\Services\ManufacturingYieldReportService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('manufacturing_yield')]
#[Title('Manufacturing yield')]
#[Description(<<<'TEXT'
Production (manufacturing orders closed in the period) per produced item: orders, expected vs actual quantity, variance and its value, and the variance percent. Negative variance = less produced than the recipe expects (loss).
Use for "which recipe loses most", "production yield this month", "how much did we produce of X".
Examples: «ما الأصناف المصنّعة الأعلى فاقدًا هذا الشهر؟», "Expected vs actual output last week".
TEXT)]
#[IsReadOnly]
class ManufacturingYieldTool extends BusinessTool
{
    protected function reportPages(): array
    {
        return [ManufacturingYieldReport::class];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->periodSchema($schema, compare: false, default: 'last_30_days'),
            'limit' => $schema->integer()->description('Default 10, max 100.'),
        ];
    }

    protected function run(Request $request): array
    {
        $period = $this->period($request, 'last_30_days', 'none');
        $rows = ManufacturingYieldReportService::query($period->from->toDateString(), $period->to->toDateString())
            ->orderBy('variance_value')
            ->limit($this->limit($request))
            ->get()
            ->map(fn ($row) => [
                'product' => $row->getAttribute('product_name'),
                'warehouse' => $row->getAttribute('warehouse_name'),
                'orders' => (int) $row->getAttribute('order_count'),
                'expected_quantity' => round((float) $row->getAttribute('expected_quantity'), 3),
                'actual_quantity' => round((float) $row->getAttribute('actual_quantity'), 3),
                'variance_quantity' => round((float) $row->getAttribute('variance_quantity'), 3),
                'variance_value' => round((float) $row->getAttribute('variance_value'), 2),
                'variance_percent' => $row->getAttribute('variance_percentage') !== null ? round((float) $row->getAttribute('variance_percentage'), 1) : null,
            ])->all();

        return $this->envelope($period, ['items' => $rows], [
            'variance_percent' => '(actual − expected) / expected, percent. Rows are sorted by the biggest loss in value first.',
        ], $rows === [] ? ['No closed manufacturing orders in this period.'] : []);
    }
}
