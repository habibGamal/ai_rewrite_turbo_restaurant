<?php

namespace App\Filament\Widgets;

use App\Enums\RevenueMethod;
use App\Filament\Exports\PeriodShiftRevenuesDetailedExporter;
use App\Filament\Widgets\Concerns\ScopesReportToTerminal;
use App\Models\Revenue;
use App\Services\FeatureService;
use App\Services\ShiftsReportService;
use Filament\Actions\ExportAction;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Revenue rows of the shifts in the report's scope (read-only; they are
 * changed on «مصروفات وإيرادات الورديات»).
 */
class PeriodShiftRevenuesDetailsTable extends BaseWidget
{
    use InteractsWithPageFilters;
    use ScopesReportToTerminal;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'تفاصيل الإيرادات الأخرى';

    public static function canView(): bool
    {
        return app(FeatureService::class)->revenuesEnabled();
    }

    public function table(Table $table): Table
    {
        $this->reportTerminal();

        $shiftIds = app(ShiftsReportService::class)->shiftIdsQueryFromFilters($this->pageFilters);

        return $table
            ->query($this->reportTerminal()->money(Revenue::query(), 'revenues.terminal_session_id')->whereIn('shift_id', $shiftIds)->with(['revenueType', 'shift.user', 'user', 'terminalSession.terminal']))
            ->headerActions([
                ExportAction::make()
                    ->label('تصدير تفاصيل الإيرادات')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->exporter(PeriodShiftRevenuesDetailedExporter::class)
                    ->fileName(fn () => 'period-shift-revenues-detailed-'.now()->format('Y-m-d-H-i-s').'.xlsx'),
            ])
            ->columns([
                TextColumn::make('revenueType.name')->label('بند الإيراد')->sortable(),
                TextColumn::make('terminalSession.terminal.name')
                    ->label('منفذ البيع')
                    ->placeholder('—')
                    ->visible(fn (): bool => app(FeatureService::class)->multiTerminalEnabled())
                    ->toggleable(),
                TextColumn::make('amount')
                    ->label('المبلغ')
                    ->money('EGP')
                    ->sortable()
                    ->summarize(Sum::make()->label('الإجمالي')->money('EGP')),
                TextColumn::make('method')->label('طريقة الدفع')->badge(),
                TextColumn::make('shift_id')->label('الوردية')->formatStateUsing(fn ($state) => "شفت #{$state}"),
                TextColumn::make('shift.user.name')->label('الكاشير')->placeholder('—'),
                TextColumn::make('notes')->label('ملاحظات')->limit(50)->placeholder('—')->wrap(),
                TextColumn::make('created_at')->label('التاريخ')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('revenueType')->label('بند الإيراد')->relationship('revenueType', 'name')->preload(),
                SelectFilter::make('method')->label('طريقة الدفع')->options(RevenueMethod::class),
            ])
            ->striped()
            ->paginated([10, 25, 50, 100])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('لا توجد إيرادات أخرى')
            ->emptyStateIcon('heroicon-o-arrow-trending-up');
    }
}
