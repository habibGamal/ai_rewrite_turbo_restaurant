<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Filament\Pages\Reports\CustomFeesReport;
use App\Filament\Pages\Reports\EquipmentReport;
use App\Services\CustomFeesReportService;
use App\Services\FeatureService;
use App\Services\Reports\EquipmentReportService;
use App\Services\ShiftsReportService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('equipment_and_fees')]
#[Title('Equipment rentals and custom fees')]
#[Description(<<<'TEXT'
Equipment charged on orders (uses, revenue, cost, profit, refunds per equipment) and custom fees (count, amount, refunds per fee name) for a period. Only what this shop has turned on.
Use for "how much did equipment rentals bring", "most used equipment", "total of the packaging fee this month".
Examples: «كم حققت المعدات من إيراد هذا الشهر؟», «ما إجمالي رسوم التغليف هذا الأسبوع؟».
TEXT)]
#[IsReadOnly]
class EquipmentAndFeesTool extends BusinessTool
{
    protected function reportPages(): array
    {
        return [EquipmentReport::class, CustomFeesReport::class];
    }

    public function allowed(): bool
    {
        $features = app(FeatureService::class);

        return ($features->equipmentEnabled() || $features->customFeesEnabled()) && parent::allowed();
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->periodSchema($schema, compare: false);
    }

    protected function run(Request $request): array
    {
        $period = $this->period($request, 'this_month', 'none');
        $features = app(FeatureService::class);
        $scope = app(ShiftsReportService::class)->shiftIdsQuery($period->from->toDateString(), $period->to->toDateString());
        $data = [];

        if ($features->equipmentEnabled() && EquipmentReport::canAccess()) {
            $service = app(EquipmentReportService::class);
            $data['equipment'] = [
                'totals' => $service->summary(clone $scope),
                'items' => $service->perEquipmentQuery(clone $scope)->get()
                    ->filter(fn ($row) => (float) $row->getAttribute('uses') > 0 || (float) $row->getAttribute('refunded') > 0)
                    ->map(fn ($row) => [
                        'equipment' => $row->getAttribute('name'),
                        'orders' => (int) $row->getAttribute('orders_count'),
                        'uses' => (float) $row->getAttribute('uses'),
                        'revenue' => round((float) $row->getAttribute('revenue'), 2),
                        'cost' => round((float) $row->getAttribute('cost'), 2),
                        'refunded' => round((float) $row->getAttribute('refunded'), 2),
                    ])->sortByDesc('revenue')->values()->all(),
            ];
        }

        if ($features->customFeesEnabled() && CustomFeesReport::canAccess()) {
            $data['fees'] = array_values(app(CustomFeesReportService::class)->summaryByName(clone $scope));
        }

        return $this->envelope($period, $data, [
            'equipment.net_profit' => 'Equipment revenue − refunds − equipment cost.',
            'fees.net' => 'Fees charged − fees refunded.',
            'period' => 'Shifts that started in the period (the equipment and fees reports\' rule).',
        ]);
    }
}
