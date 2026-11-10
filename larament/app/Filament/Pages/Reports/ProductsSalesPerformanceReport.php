<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Components\PeriodFilterFormComponent;
use App\Filament\Traits\ViewerAccess;
use App\Filament\Widgets\CategoryPerformanceWidget;
use App\Filament\Widgets\NoProductsSalesInPeriodWidget;
use App\Filament\Widgets\OrderTypePerformanceWidget;
use App\Filament\Widgets\ProductsSalesGroupedWidget;
use App\Filament\Widgets\ProductsSalesStatsWidget;
use App\Filament\Widgets\ProductsSalesTableWidget;
use App\Filament\Widgets\TopProductsByProfitWidget;
use App\Filament\Widgets\TopProductsBySalesWidget;
use App\Models\Category;
use App\Services\ProductsSalesReportService;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class ProductsSalesPerformanceReport extends BaseDashboard
{
    use HasFiltersForm, ViewerAccess;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static string $routePath = 'products-sales-performance-report';

    protected static string|\UnitEnum|null $navigationGroup = 'التقارير';

    protected static ?string $navigationLabel = 'تقرير أداء المنتجات';

    protected static ?string $title = 'تقرير أداء المنتجات في المبيعات';

    protected static ?int $navigationSort = 4;

    protected ProductsSalesReportService $productsReportService;

    public function boot(): void
    {
        $this->productsReportService = app(ProductsSalesReportService::class);
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                PeriodFilterFormComponent::make(
                    'اختر الفترة الزمنية لعرض أداء المنتجات في المبيعات',
                    'last_30_days',
                    29
                ),
                Section::make('التصنيف')
                    ->description('صفِّ التقرير حسب التصنيف الرئيسي أو الفرعي')
                    ->columnSpanFull()
                    ->schema([
                        Select::make('mainCategoryId')
                            ->label('التصنيف الرئيسي')
                            ->placeholder('كل التصنيفات الرئيسية')
                            ->options(fn (): array => Category::query()
                                ->whereNull('parent_id')
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('subCategoryId', null)),
                        Select::make('subCategoryId')
                            ->label('التصنيف الفرعي')
                            ->placeholder('كل التصنيفات الفرعية')
                            ->options(function (Get $get): array {
                                $mainId = $get('mainCategoryId');

                                return Category::query()
                                    ->whereNotNull('parent_id')
                                    ->when(is_numeric($mainId), fn ($query) => $query->where('parent_id', (int) $mainId))
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all();
                            })
                            ->searchable()
                            ->live(),
                    ])
                    ->columns(2),
            ]);
    }

    public function getWidgets(): array
    {
        $startDate = $this->filters['startDate'] ?? now()->subDays(29)->startOfDay()->toDateString();
        $endDate = $this->filters['endDate'] ?? now()->endOfDay()->toDateString();
        $ordersCount = $this->productsReportService->getOrdersQuery(
            $startDate,
            $endDate
        )->count();

        if ($ordersCount === 0) {
            return [
                NoProductsSalesInPeriodWidget::class,
            ];
        }

        return [
            ProductsSalesStatsWidget::class,
            TopProductsBySalesWidget::class,
            TopProductsByProfitWidget::class,
            OrderTypePerformanceWidget::class,
            CategoryPerformanceWidget::class,
            ProductsSalesGroupedWidget::class,
            ProductsSalesTableWidget::class,
        ];
    }
}
