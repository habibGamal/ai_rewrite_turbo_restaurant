<?php

namespace App\Filament\Pages\Reports;

use App\Models\User;
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
            $this->cashier = User::query()->findOrFail((int) $record);
        }
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
        return 'البريد الإلكتروني: '.($this->cashier->email ?? '—');
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
