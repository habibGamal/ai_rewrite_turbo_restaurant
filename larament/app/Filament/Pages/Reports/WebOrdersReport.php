<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Widgets\WebOrdersStats;
use App\Filament\Widgets\WebOrdersTable;
use App\Filament\Traits\ViewerAccess;
use Filament\Pages\Dashboard as BaseDashboard;

class WebOrdersReport extends BaseDashboard
{
    use ViewerAccess;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-globe-alt';

    protected static string $routePath = 'web-orders-report';

    protected static string | \UnitEnum | null $navigationGroup = 'التقارير';

    protected static ?string $navigationLabel = 'أوردرات الويب';

    protected static ?string $title = 'أوردرات الويب';

    protected static ?int $navigationSort = 2;

    public function getWidgets(): array
    {
        return [
            WebOrdersStats::class,
            WebOrdersTable::class,
        ];
    }
}
