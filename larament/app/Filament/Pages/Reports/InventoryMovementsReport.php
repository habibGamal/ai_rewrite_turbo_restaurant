<?php

namespace App\Filament\Pages\Reports;

use App\Exports\InventoryMovementsExport;
use App\Filament\Components\PeriodFilterFormComponent;
use App\Filament\Traits\ViewerAccess;
use App\Filament\Widgets\InventoryItemsTableWidget;
use App\Filament\Widgets\InventoryMovementsKpisWidget;
use App\Filament\Widgets\InventoryMovementTypesWidget;
use App\Filament\Widgets\InventoryValueBridgeWidget;
use App\Models\Category;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Reports\Inventory\InventoryMovementsReportService;
use App\Services\Reports\Inventory\InventoryReportFilters;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ToggleButtons;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * «تقرير حركة المخزون»: one report for every stock movement (purchases,
 * sales consumption, waste, stock issues, stocktaking, transfers,
 * manufacturing, returns). It replaces the per-document reports, which
 * only repeated their resources' lists. «كشف حركة صنف» stays the
 * per-item drill-down.
 */
class InventoryMovementsReport extends BaseDashboard
{
    use HasFiltersForm, ViewerAccess;

    public const TAB_OVERVIEW = 'overview';

    public const TAB_ITEMS = 'items';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static string $routePath = 'inventory-movements-report';

    protected static string|\UnitEnum|null $navigationGroup = 'التقارير';

    protected static ?string $navigationLabel = 'تقرير حركة المخزون';

    protected static ?string $title = 'تقرير حركة المخزون';

    protected static ?int $navigationSort = 2;

    /**
     * @return array<string, string>
     */
    public static function tabs(): array
    {
        return [
            self::TAB_OVERVIEW => 'نظرة عامة',
            self::TAB_ITEMS => 'الأصناف',
        ];
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                PeriodFilterFormComponent::make('اختر الفترة الزمنية لحركة المخزون', 'last_30_days', 29),
                Section::make('المقارنة والتصفية')
                    ->columnSpanFull()
                    ->columns(5)
                    ->schema([
                        Select::make('compareTo')
                            ->label('قارن بـ')
                            ->options(InventoryReportFilters::compareOptions())
                            ->default(InventoryReportFilters::COMPARE_PREVIOUS)
                            ->selectablePlaceholder(false)
                            ->live(),
                        Select::make('warehouse_id')
                            ->label('المخزن')
                            ->placeholder('كل المخازن')
                            ->options(fn (): array => Warehouse::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->live(),
                        Select::make('main_category_id')
                            ->label('التصنيف الرئيسي')
                            ->placeholder('كل التصنيفات')
                            ->options(fn (): array => Category::query()->whereNull('parent_id')->orderBy('name')->pluck('name', 'id')->all())
                            ->afterStateUpdated(function (Set $set): void {
                                $set('sub_category_id', null);
                                $set('product_id', null);
                            })
                            ->searchable()
                            ->live(),
                        Select::make('sub_category_id')
                            ->label('التصنيف الفرعي')
                            ->placeholder('كل التصنيفات الفرعية')
                            ->options(fn (Get $get): array => Category::query()
                                ->whereNotNull('parent_id')
                                ->when(is_numeric($get('main_category_id')), fn (Builder $query) => $query->where('parent_id', (int) $get('main_category_id')))
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->afterStateUpdated(fn (Set $set) => $set('product_id', null))
                            ->searchable()
                            ->live(),
                        Select::make('product_id')
                            ->label('الصنف')
                            ->placeholder('كل الأصناف')
                            ->options(function (Get $get): array {
                                $mainId = $get('main_category_id');
                                $subId = $get('sub_category_id');

                                return Product::query()
                                    ->when(is_numeric($subId), fn (Builder $query) => $query->where('category_id', (int) $subId))
                                    ->when(is_numeric($mainId) && ! is_numeric($subId), fn (Builder $query) => $query->whereIn(
                                        'category_id',
                                        Category::query()->select('id')->where('id', (int) $mainId)->orWhere('parent_id', (int) $mainId),
                                    ))
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all();
                            })
                            ->searchable()
                            ->live(),
                    ]),
                ToggleButtons::make('tab')
                    ->hiddenLabel()
                    ->options(self::tabs())
                    ->icons([
                        self::TAB_OVERVIEW => 'heroicon-o-chart-bar-square',
                        self::TAB_ITEMS => 'heroicon-o-cube',
                    ])
                    ->default(self::TAB_OVERVIEW)
                    ->inline()
                    ->grouped()
                    ->columnSpanFull()
                    ->live()
                    // The filters form re-renders on its own; switching the tab
                    // swaps the widgets, so the whole page has to render.
                    ->afterStateUpdated(fn () => $this->forceRender()),
            ]);
    }

    public function activeTab(): string
    {
        $tab = $this->filters['tab'] ?? self::TAB_OVERVIEW;

        return array_key_exists($tab, self::tabs()) ? $tab : self::TAB_OVERVIEW;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportInventoryMovements')
                ->label('تصدير Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(function (): BinaryFileResponse {
                    $filters = InventoryReportFilters::fromArray($this->filters);

                    return Excel::download(
                        new InventoryMovementsExport(app(InventoryMovementsReportService::class), $filters),
                        'inventory-movements-'.$filters->startDate().'-'.$filters->endDate().'.xlsx',
                    );
                }),
        ];
    }

    public function getWidgets(): array
    {
        return match ($this->activeTab()) {
            self::TAB_ITEMS => [
                InventoryMovementsKpisWidget::class,
                InventoryItemsTableWidget::class,
            ],
            default => [
                InventoryMovementsKpisWidget::class,
                InventoryValueBridgeWidget::class,
                InventoryMovementTypesWidget::class,
            ],
        };
    }
}
