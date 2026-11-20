<?php

namespace App\Filament\Widgets\ShiftMoney;

use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Widgets\ShiftMoney\Concerns\InteractsWithSelectedShift;
use App\Models\AuditLog;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Livewire\Attributes\On;

/**
 * «السجل»: every audited change of the picked shift (its expenses, revenues
 * and its own recomputed cash), newest first.
 */
class ShiftMoneyAuditTable extends BaseWidget
{
    use InteractsWithSelectedShift;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'السجل';

    /** Fields worth showing in the change summary, with their Arabic names. */
    private const FIELDS = [
        'amount' => 'المبلغ',
        'method' => 'الطريقة',
        'notes' => 'ملاحظات',
        'expence_type_id' => 'نوع المصروف',
        'revenue_type_id' => 'بند الإيراد',
        'end_cash' => 'النقدية المتوقعة',
        'losses_amount' => 'العجز',
    ];

    #[On(self::CHANGED_EVENT)]
    public function refreshRows(): void {}

    public function table(Table $table): Table
    {
        $shiftId = $this->selectedShift()->id ?? 0;

        return $table
            ->query(AuditLog::query()->where('shift_id', $shiftId)->with('user'))
            ->columns([
                TextColumn::make('created_at')->label('الوقت')->dateTime('d/m/Y H:i'),
                TextColumn::make('action')->label('العملية')->badge(),
                TextColumn::make('auditable_type')
                    ->label('النوع')
                    ->formatStateUsing(fn (?string $state): string => AuditLogResource::typeLabel($state)),
                TextColumn::make('changes')
                    ->label('التغيير')
                    ->state(fn (AuditLog $record): string => self::describe($record))
                    ->wrap(),
                TextColumn::make('user.name')->label('المستخدم')->placeholder('—'),
                TextColumn::make('reason')->label('السبب')->placeholder('—')->wrap(),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([10, 25, 50])
            ->emptyStateHeading('لا توجد تعديلات مسجلة لهذه الوردية')
            ->emptyStateIcon('heroicon-o-clipboard-document-list');
    }

    public static function describe(AuditLog $log): string
    {
        $old = (array) ($log->old_values ?? []);
        $new = (array) ($log->new_values ?? []);
        $parts = [];

        foreach (self::FIELDS as $key => $label) {
            $before = $old[$key] ?? null;
            $after = $new[$key] ?? null;

            if ($before === null && $after === null) {
                continue;
            }

            $parts[] = match (true) {
                $before === null => "{$label}: {$after}",
                $after === null => "{$label}: {$before}",
                default => "{$label}: {$before} ← {$after}",
            };
        }

        return $parts === [] ? '—' : implode(' · ', $parts);
    }
}
