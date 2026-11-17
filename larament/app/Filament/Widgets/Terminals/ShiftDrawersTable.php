<?php

namespace App\Filament\Widgets\Terminals;

use App\Models\TerminalSession;
use App\Services\FeatureService;
use App\Terminals\TerminalSessionService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The drawers of the report's shifts: who held each one, when it opened and
 * closed, and its cash in, cash out and expected cash. Shown only while
 * multiple terminals are on.
 */
abstract class ShiftDrawersTable extends BaseWidget
{
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'منافذ البيع';

    /** @var array<int, array<string, float>> */
    private array $breakdowns = [];

    /**
     * The ids of the shifts the report shows.
     */
    abstract protected function shiftIds(): QueryBuilder;

    public static function canView(): bool
    {
        return app(FeatureService::class)->multiTerminalEnabled();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => TerminalSession::query()
                ->whereIn('shift_id', $this->shiftIds())
                ->with(['terminal', 'user', 'closedBy'])
                ->withCount('handovers'))
            ->defaultSort('opened_at')
            ->columns([
                TextColumn::make('terminal.name')->label('منفذ البيع')->weight('medium'),
                TextColumn::make('shift_id')->label('الوردية')->sortable(),
                TextColumn::make('user.name')->label('الكاشير')->placeholder('—')
                    ->description(fn (TerminalSession $record): ?string => $record->handovers_count > 0 ? 'اتسلم '.$record->handovers_count.' مرة' : null),
                TextColumn::make('opened_at')->label('فتح')->dateTime('d/m H:i')->sortable(),
                TextColumn::make('closed_at')->label('قفل')->dateTime('d/m H:i')->placeholder('مفتوح')
                    ->description(fn (TerminalSession $record): ?string => $record->closedBy !== null && $record->closed_by !== $record->user_id ? 'قفله '.$record->closedBy->name : null),
                TextColumn::make('opening_cash')->label('الفكة')->money('EGP'),
                TextColumn::make('cash_in')->label('نقدية داخلة')->money('EGP')->color('success')
                    ->state(fn (TerminalSession $record): float => $this->breakdown($record)['cash_in']),
                TextColumn::make('cash_out')->label('نقدية خارجة')->money('EGP')->color('danger')
                    ->state(fn (TerminalSession $record): float => $this->breakdown($record)['cash_out']),
                TextColumn::make('expected')->label('النقدية المتوقعة')->money('EGP')->weight('bold')
                    ->state(fn (TerminalSession $record): float => $record->isOpen()
                        ? $this->breakdown($record)['expected']
                        : (float) $record->expected_cash),
                TextColumn::make('close_note')->label('ملاحظة القفل')->placeholder('—')->limit(40)
                    ->tooltip(fn (TerminalSession $record): ?string => $record->close_note)
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('terminal_id')->label('منفذ البيع')->relationship('terminal', 'name'),
            ])
            ->paginated(false)
            ->emptyStateHeading('لا توجد منافذ بيع')
            ->recordAction(null)
            ->recordUrl(null);
    }

    /**
     * @return array<string, float>
     */
    private function breakdown(TerminalSession $session): array
    {
        return $this->breakdowns[$session->getKey()] ??= app(TerminalSessionService::class)->breakdown($session);
    }
}
