<?php

namespace App\Filament\Widgets\ShiftMoney;

use App\Enums\RevenueMethod;
use App\Filament\Widgets\ShiftMoney\Concerns\InteractsWithSelectedShift;
use App\Models\Revenue;
use App\Models\RevenueType;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Livewire\Attributes\On;

/**
 * Misc revenues of the picked shift (shown only while REVENUES is on).
 */
class ShiftMoneyRevenuesTable extends BaseWidget
{
    use InteractsWithSelectedShift;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'الإيرادات الأخرى';

    #[On(self::CHANGED_EVENT)]
    public function refreshRows(): void {}

    public function table(Table $table): Table
    {
        $shiftId = $this->selectedShift()->id ?? 0;

        return $table
            ->query(Revenue::query()->where('shift_id', $shiftId)->with(['revenueType', 'user', 'terminalSession.terminal']))
            ->columns([
                TextColumn::make('revenueType.name')->label('بند الإيراد'),
                $this->drawerColumn(),
                TextColumn::make('amount')
                    ->label('المبلغ')
                    ->money('EGP')
                    ->summarize(Sum::make()->label('الإجمالي')->money('EGP')),
                TextColumn::make('method')->label('طريقة الدفع')->badge(),
                TextColumn::make('notes')->label('ملاحظات')->limit(60)->placeholder('—')->wrap(),
                TextColumn::make('user.name')->label('بواسطة')->placeholder('—'),
                TextColumn::make('created_at')->label('الوقت')->dateTime('d/m/Y H:i'),
            ])
            ->headerActions([
                Action::make('addRevenue')
                    ->label('إضافة إيراد')
                    ->icon('heroicon-o-plus')
                    ->visible(fn (): bool => $this->canEditSelectedShift())
                    ->schema($this->formSchema())
                    ->action(function (array $data): void {
                        $this->runShiftMoneyChange(
                            fn () => $this->shiftMoney()->addRevenue($this->selectedShift(), $data, $data['reason'] ?? null),
                            'تم إضافة الإيراد',
                        );
                    }),
            ])
            ->recordActions([
                Action::make('editRevenue')
                    ->label('تعديل')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn (): bool => $this->canEditSelectedShift())
                    ->fillForm(fn (Revenue $record): array => [
                        'revenue_type_id' => $record->revenue_type_id,
                        'amount' => $record->amount,
                        'method' => $record->method->value,
                        'notes' => $record->notes,
                        'terminal_session_id' => $record->getAttribute('terminal_session_id'),
                    ])
                    ->schema($this->formSchema())
                    ->action(function (Revenue $record, array $data): void {
                        $this->runShiftMoneyChange(
                            fn () => $this->shiftMoney()->updateRevenue($record, $data, $data['reason'] ?? null),
                            'تم تعديل الإيراد',
                        );
                    }),
                Action::make('deleteRevenue')
                    ->label('حذف')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('حذف الإيراد')
                    ->visible(fn (): bool => $this->canEditSelectedShift())
                    ->schema([$this->reasonField()])
                    ->action(function (Revenue $record, array $data): void {
                        $this->runShiftMoneyChange(
                            fn () => $this->shiftMoney()->deleteRevenue($record, $data['reason'] ?? null),
                            'تم حذف الإيراد',
                        );
                    }),
            ])
            ->paginated(false)
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('لا توجد إيرادات لهذه الوردية')
            ->emptyStateIcon('heroicon-o-arrow-trending-up');
    }

    /**
     * @return array<int, Field>
     */
    private function formSchema(): array
    {
        return [
            Select::make('revenue_type_id')
                ->label('بند الإيراد')
                ->options(fn (?Revenue $record): array => RevenueType::query()
                    ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $record?->revenue_type_id))
                    ->ordered()
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->required(),
            TextInput::make('amount')
                ->label('المبلغ')
                ->numeric()
                ->minValue(0.01)
                ->required()
                ->prefix('ج.م'),
            ToggleButtons::make('method')
                ->label('طريقة الدفع')
                ->options(RevenueMethod::class)
                ->default(RevenueMethod::CASH->value)
                ->inline()
                ->required(),
            Textarea::make('notes')
                ->label('ملاحظات')
                ->rows(2)
                ->maxLength(255),
            $this->drawerField(),
            $this->reasonField(),
        ];
    }
}
