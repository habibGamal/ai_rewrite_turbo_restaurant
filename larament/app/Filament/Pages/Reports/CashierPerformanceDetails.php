<?php

namespace App\Filament\Pages\Reports;

use App\Models\User;
use App\Services\ShiftsReportService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Panel;
use Illuminate\Contracts\Support\Htmlable;

class CashierPerformanceDetails extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user';

    protected static string|\UnitEnum|null $navigationGroup = 'التقارير';

    protected static ?string $navigationLabel = 'تفاصيل أداء الكاشير';

    protected static ?string $title = 'تفاصيل أداء الكاشير';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.reports.cashier-performance-details';

    public User $cashier;

    public string $activeTab = 'orders';

    public string $filterType = 'period';

    public ?string $startDate = null;

    public ?string $endDate = null;

    public ?string $presetPeriod = null;

    public array $shifts = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        return $user->can('view_reports') || $user->can('view_cashier_commission_report');
    }

    public static function getRoutePath(Panel $panel): string
    {
        return '/reports/cashier-performance/{record}';
    }

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'reports.cashier-performance-details';
    }

    public function mount(int|string|User $record): void
    {
        abort_unless(static::canAccess(), 403);

        if ($record instanceof User) {
            $this->cashier = $record;
        } else {
            $this->cashier = User::query()->where('is_vendor', false)->findOrFail((int) $record);
        }

        $this->filterType = (string) request()->query('filterType', $this->filterType ?: 'period');
        $this->startDate = request()->query('startDate', $this->startDate);
        $this->endDate = request()->query('endDate', $this->endDate);
        $this->presetPeriod = request()->query('presetPeriod', $this->presetPeriod);

        $shiftsParam = request()->query('shifts', $this->shifts);
        if (! empty($shiftsParam)) {
            $this->shifts = is_array($shiftsParam) ? $shiftsParam : explode(',', (string) $shiftsParam);
        }

        if ($this->filterType === 'period') {
            $this->startDate ??= now()->subDays(6)->startOfDay()->toDateString();
            $this->endDate ??= now()->endOfDay()->toDateString();
            $this->presetPeriod ??= 'last_7_days';
        }
    }

    public function getResolvedFilter(): array
    {
        $shiftsReportService = app(ShiftsReportService::class);
        $shiftIds = [];
        $startDateCarbon = null;
        $endDateCarbon = null;

        if ($this->filterType === 'shifts') {
            $shiftIds = array_values(array_filter(array_map('intval', (array) $this->shifts)));
        } else {
            $startDateCarbon = Carbon::parse($this->startDate ?? now()->subDays(6)->startOfDay()->toDateString())->startOfDay();
            $endDateCarbon = Carbon::parse($this->endDate ?? now()->endOfDay()->toDateString())->endOfDay();

            $shiftIds = $shiftsReportService->getShiftsInPeriodQuery($startDateCarbon->toDateString(), $endDateCarbon->toDateString(), null)
                ->pluck('id')
                ->toArray();
        }

        return [
            'filterType' => $this->filterType,
            'shiftIds' => $shiftIds,
            'startDate' => $startDateCarbon?->toDateTimeString(),
            'endDate' => $endDateCarbon?->toDateTimeString(),
            'hasFilter' => true,
        ];
    }

    public function getTitle(): string|Htmlable
    {
        return 'تفاصيل أداء الكاشير: '.($this->cashier->name ?? '');
    }

    public function getHeading(): string|Htmlable
    {
        return 'تفاصيل أداء الكاشير: '.($this->cashier->name ?? '');
    }

    public function getSubheading(): ?string
    {
        $periodLabels = [
            'today' => 'اليوم',
            'yesterday' => 'أمس',
            'last_7_days' => 'آخر 7 أيام',
            'last_30_days' => 'آخر 30 يوم',
            'this_week' => 'هذا الأسبوع',
            'last_week' => 'الأسبوع الماضي',
            'this_month' => 'هذا الشهر',
            'last_month' => 'الشهر الماضي',
            'this_year' => 'هذا العام',
            'custom' => 'فترة مخصصة',
        ];

        $filterDesc = '';
        if ($this->filterType === 'shifts') {
            $count = count($this->shifts);
            $filterDesc = "تصفية بالشفتات ({$count} شفت محدد)";
        } else {
            $preset = $periodLabels[$this->presetPeriod ?? ''] ?? null;
            $filterDesc = 'الفترة: '.($preset ? "{$preset} (" : '').$this->startDate.' إلى '.$this->endDate.($preset ? ')' : '');
        }

        $email = $this->cashier->email ? "البريد: {$this->cashier->email}" : '';

        return implode(' | ', array_filter([$email, $filterDesc]));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('العودة لتقرير عمولات الكاشير')
                ->icon('heroicon-o-arrow-right')
                ->color('gray')
                ->url(CashierCommissionReport::getUrl()),
        ];
    }

    /**
     * @return array<string|int, string>
     */
    public function getBreadcrumbs(): array
    {
        return [
            CashierCommissionReport::getUrl() => CashierCommissionReport::getNavigationLabel(),
            $this->cashier->name,
        ];
    }
}
