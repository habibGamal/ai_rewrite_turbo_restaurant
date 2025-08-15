<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Traits\AdminAccess;
use App\Filament\Traits\ViewerAccess;
use App\Filament\Components\PeriodFilterFormComponent;
use Filament\Pages\Page;
use Filament\Forms\Form;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;

class StockReport extends BaseDashboard
{
    use HasFiltersForm ,ViewerAccess;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static string $routePath = 'orders-report';

    protected static ?string $navigationGroup = 'التقارير';

    protected static ?string $navigationLabel = 'تقرير المخزون';

    protected static ?string $title = 'تقرير المخزون';

    protected static ?int $navigationSort = 1;


    public function filtersForm(Form $form): Form
    {
        return $form
            ->schema([
                PeriodFilterFormComponent::make(
                    'اختر الفترة الزمنية لتحليل المخزون',
                    'last_30_days',
                    29
                ),
            ]);
    }

    public function getWidgets(): array
    {
        return [
            \App\Filament\Widgets\StockReportTable::class,
        ];
    }

}
