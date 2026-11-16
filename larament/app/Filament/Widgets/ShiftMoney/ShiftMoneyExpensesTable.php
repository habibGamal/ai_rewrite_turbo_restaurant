<?php

namespace App\Filament\Widgets\ShiftMoney;

use App\Filament\Widgets\ShiftMoney\Concerns\InteractsWithSelectedShift;
use App\Models\ExpenceType;
use App\Models\Expense;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Livewire\Attributes\On;

class ShiftMoneyExpensesTable extends BaseWidget
{
    use InteractsWithSelectedShift;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'المصروفات';

    #[On(self::CHANGED_EVENT)]
    public function refreshRows(): void {}

    public function table(Table $table): Table
    {
        $shiftId = $this->selectedShift()->id ?? 0;

        return $table
            ->query(Expense::query()->where('shift_id', $shiftId)->with(['expenceType', 'terminalSession.terminal']))
            ->columns([
                TextColumn::make('expenceType.name')->label('نوع المصروف'),
                $this->drawerColumn(),
                TextColumn::make('amount')
                    ->label('المبلغ')
                    ->money('EGP')
                    ->summarize(Sum::make()->label('الإجمالي')->money('EGP')),
                TextColumn::make('notes')->label('ملاحظات')->limit(60)->placeholder('—')->wrap(),
                TextColumn::make('created_at')->label('الوقت')->dateTime('d/m/Y H:i'),
            ])
            ->headerActions([
                Action::make('addExpense')
                    ->label('إضافة مصروف')
                    ->icon('heroicon-o-plus')
                    ->visible(fn (): bool => $this->canEditSelectedShift())
                    ->schema($this->formSchema())
                    ->action(function (array $data): void {
                        $this->runShiftMoneyChange(
                            fn () => $this->shiftMoney()->addExpense($this->selectedShift(), $data, $data['reason'] ?? null),
                            'تم إضافة المصروف',
                        );
                    }),
            ])
            ->recordActions([
                Action::make('editExpense')
                    ->label('تعديل')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn (): bool => $this->canEditSelectedShift())
                    ->fillForm(fn (Expense $record): array => [
                        'expence_type_id' => $record->expence_type_id,
                        'amount' => $record->amount,
                        'notes' => $record->notes,
                        'terminal_session_id' => $record->getAttribute('terminal_session_id'),
                    ])
                    ->schema($this->formSchema())
                    ->action(function (Expense $record, array $data): void {
                        $this->runShiftMoneyChange(
                            fn () => $this->shiftMoney()->updateExpense($record, $data, $data['reason'] ?? null),
                            'تم تعديل المصروف',
                        );
                    }),
                Action::make('deleteExpense')
                    ->label('حذف')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('حذف المصروف')
                    ->visible(fn (): bool => $this->canEditSelectedShift())
                    ->schema([$this->reasonField()])
                    ->action(function (Expense $record, array $data): void {
                        $this->runShiftMoneyChange(
                            fn () => $this->shiftMoney()->deleteExpense($record, $data['reason'] ?? null),
                            'تم حذف المصروف',
                        );
                    }),
            ])
            ->paginated(false)
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('لا توجد مصروفات لهذه الوردية')
            ->emptyStateIcon('heroicon-o-banknotes');
    }

    /**
     * @return array<int, Component|Field>
     */
    private function formSchema(): array
    {
        return [
            Select::make('expence_type_id')
                ->label('نوع المصروف')
                ->options(fn (): array => ExpenceType::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->required(),
            TextInput::make('amount')
                ->label('المبلغ')
                ->numeric()
                ->minValue(0.01)
                ->required()
                ->prefix('ج.م'),
            Textarea::make('notes')
                ->label('ملاحظات')
                ->rows(2)
                ->maxLength(255),
            $this->drawerField(),
            $this->reasonField(),
        ];
    }
}
