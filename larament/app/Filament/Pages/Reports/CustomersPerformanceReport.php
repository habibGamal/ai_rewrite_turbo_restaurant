<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Components\PeriodFilterFormComponent;
use App\Filament\Traits\ViewerAccess;
use App\Filament\Widgets\CustomerActivityTrendWidget;
use App\Filament\Widgets\CustomerOrderTypePerformanceWidget;
use App\Filament\Widgets\CustomersAtRiskTableWidget;
use App\Filament\Widgets\CustomerSegmentsWidget;
use App\Filament\Widgets\CustomersPerformanceStatsWidget;
use App\Filament\Widgets\CustomersPerformanceTableWidget;
use App\Filament\Widgets\NoCustomersSalesInPeriodWidget;
use App\Filament\Widgets\TopCustomersByProfitWidget;
use App\Filament\Widgets\TopCustomersBySalesWidget;
use App\Services\CustomersPerformanceReportService;
use App\Services\Reports\CustomerReportThresholds;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CustomersPerformanceReport extends BaseDashboard
{
    use HasFiltersForm, ViewerAccess;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static string $routePath = 'customers-performance-report';

    protected static string|\UnitEnum|null $navigationGroup = 'التقارير';

    protected static ?string $navigationLabel = 'تقرير أداء العملاء';

    protected static ?string $title = 'تقرير أداء العملاء في المبيعات';

    protected static ?int $navigationSort = 12;

    protected CustomersPerformanceReportService $customersReportService;

    public function boot(): void
    {
        $this->customersReportService = app(CustomersPerformanceReportService::class);
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                PeriodFilterFormComponent::make(
                    'اختر الفترة الزمنية لعرض أداء العملاء في المبيعات',
                    'last_30_days',
                    29
                ),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reportSettings')
                ->label('إعدادات التقرير')
                ->icon('heroicon-o-adjustments-horizontal')
                ->color('gray')
                ->visible(fn (): bool => (bool) auth()->user()?->isAdmin())
                ->modalHeading('إعدادات تقرير أداء العملاء')
                ->modalDescription('تُطبَّق الحدود على الفترة المختارة في التقرير. المبالغ بالجنيه وصافية بعد الخصومات والمرتجعات.')
                ->modalSubmitActionLabel('حفظ')
                ->fillForm(fn (): array => app(CustomerReportThresholds::class)->all())
                ->schema([
                    Section::make('تصنيف العملاء')
                        ->columns(2)
                        ->schema([
                            self::thresholdInput('vip_min_sales', 'VIP: أقل قيمة مشتريات', 'ج.م'),
                            self::thresholdInput('vip_min_orders', 'VIP: أقل عدد طلبات', 'طلب'),
                            self::thresholdInput('loyal_min_sales', 'مخلص: أقل قيمة مشتريات', 'ج.م'),
                            self::thresholdInput('loyal_min_orders', 'مخلص: أقل عدد طلبات', 'طلب'),
                            self::thresholdInput('regular_min_orders', 'عادي: أقل عدد طلبات', 'طلب')
                                ->helperText('من هم أقل من ذلك يُصنَّفون «عرضي».'),
                        ]),
                    Section::make('العملاء المتوقفون عن الطلب')
                        ->columns(2)
                        ->schema([
                            self::thresholdInput('churn_gap_multiplier', 'مضاعف المدة المعتادة', 'ضعف')
                                ->helperText('مثال: 2 = لم يطلب منذ ضعف المدة المعتادة بين طلباته.'),
                            self::thresholdInput('churn_min_days', 'أقل عدد أيام بدون طلب', 'يوم'),
                        ]),
                ])
                ->action(function (array $data): void {
                    app(CustomerReportThresholds::class)->save($data);

                    Notification::make()->title('تم حفظ إعدادات التقرير')->success()->send();

                    $this->redirect(static::getUrl());
                }),
        ];
    }

    private static function thresholdInput(string $name, string $label, string $suffix): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->numeric()
            ->minValue(0)
            ->required()
            ->suffix($suffix);
    }

    public function getWidgets(): array
    {
        [$defaultStart, $defaultEnd] = CustomersPerformanceReportService::defaultPeriod();
        $ordersCount = $this->customersReportService->getOrdersQuery(
            $this->filters['startDate'] ?? $defaultStart,
            $this->filters['endDate'] ?? $defaultEnd,
        )->count();

        if ($ordersCount === 0) {
            return [
                NoCustomersSalesInPeriodWidget::class,
                CustomersAtRiskTableWidget::class,
            ];
        }

        return [
            CustomersPerformanceStatsWidget::class,
            CustomersAtRiskTableWidget::class,
            TopCustomersBySalesWidget::class,
            TopCustomersByProfitWidget::class,
            CustomerSegmentsWidget::class,
            CustomerOrderTypePerformanceWidget::class,
            CustomerActivityTrendWidget::class,
            CustomersPerformanceTableWidget::class,
        ];
    }
}
