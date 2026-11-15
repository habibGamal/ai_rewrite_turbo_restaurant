<?php

namespace App\Filament\Pages\ShiftMoney;

use App\Filament\Widgets\ShiftMoney\ShiftMoneyAuditTable;
use App\Filament\Widgets\ShiftMoney\ShiftMoneyExpensesTable;
use App\Filament\Widgets\ShiftMoney\ShiftMoneyRevenuesTable;
use App\Filament\Widgets\ShiftMoney\ShiftMoneySummaryStats;
use App\Models\Shift;
use App\Services\FeatureService;
use App\Services\ShiftMoneyService;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

/**
 * «مصروفات وإيرادات الورديات»: the manager picks any shift (open or closed)
 * and adds, edits or deletes its expenses and revenues. Every change goes
 * through ShiftMoneyService (audit, closed-shift permission and reason,
 * expected cash recompute).
 *
 * Link to a shift with ?filters[shift_id]=<id> (see urlForShift()).
 */
class ShiftMoneyPage extends BaseDashboard
{
    use HasFiltersForm;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string $routePath = 'shift-money';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة المصروفات';

    protected static ?string $navigationLabel = 'مصروفات وإيرادات الورديات';

    protected static ?string $title = 'مصروفات وإيرادات الورديات';

    protected static ?int $navigationSort = 1;

    /** The shift is picked per visit (or by link); never carry an old pick over from the session. */
    public function persistsFiltersInSession(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return app(ShiftMoneyService::class)->canManage();
    }

    public static function urlForShift(int $shiftId): string
    {
        return static::getUrl(['filters' => ['shift_id' => $shiftId]]);
    }

    public function mount(): void
    {
        if (empty($this->filters['shift_id'])) {
            $this->filters = ['shift_id' => self::defaultShiftId()];
            $this->getFiltersForm()->fill($this->filters);
        }
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->schema([
                    Select::make('shift_id')
                        ->label('الوردية')
                        ->placeholder('اختر الوردية')
                        ->searchable()
                        ->native(false)
                        ->options(fn (): array => self::shiftOptions(Shift::query()->latest('id')->limit(30)))
                        ->getSearchResultsUsing(fn (string $search): array => self::shiftOptions(
                            Shift::query()
                                ->where(fn (Builder $query) => $query
                                    ->where('id', ltrim($search, '#'))
                                    ->orWhere('business_date', 'like', "%{$search}%")
                                    ->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', "%{$search}%")))
                                ->latest('id')
                                ->limit(30)
                        ))
                        ->getOptionLabelUsing(fn ($value): ?string => ($shift = Shift::query()->with('user')->find($value)) ? self::shiftLabel($shift) : null),
                ])
                ->columnSpanFull(),
        ]);
    }

    public function getWidgets(): array
    {
        $widgets = [
            ShiftMoneySummaryStats::class,
            ShiftMoneyExpensesTable::class,
        ];

        if (app(FeatureService::class)->revenuesEnabled()) {
            $widgets[] = ShiftMoneyRevenuesTable::class;
        }

        $widgets[] = ShiftMoneyAuditTable::class;

        return $widgets;
    }

    public function getColumns(): int|array
    {
        return 1;
    }

    public static function defaultShiftId(): ?int
    {
        $open = Shift::query()->where('closed', false)->whereNull('end_at')->latest('id')->value('id');

        return $open ?? Shift::query()->latest('id')->value('id');
    }

    public static function shiftLabel(Shift $shift): string
    {
        $state = app(ShiftMoneyService::class)->isClosed($shift) ? 'مغلقة' : 'مفتوحة';
        $date = ($shift->business_date ?? $shift->start_at)->format('Y-m-d');
        $edited = $shift->money_edited_at !== null ? ' · معدلة' : '';

        return "#{$shift->id} · {$date} · ".($shift->user->name ?? '—')." · {$state}{$edited}";
    }

    /**
     * Open shifts first, then the newest.
     *
     * @param  Builder<Shift>  $query
     * @return array<int, string>
     */
    private static function shiftOptions(Builder $query): array
    {
        $service = app(ShiftMoneyService::class);
        $options = [];

        foreach ($query->with('user')->get()->all() as $shift) {
            $options[] = [$service->isClosed($shift) ? 1 : 0, -$shift->id, $shift];
        }

        usort($options, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $labels = [];
        foreach ($options as [, , $shift]) {
            $labels[$shift->id] = self::shiftLabel($shift);
        }

        return $labels;
    }
}
