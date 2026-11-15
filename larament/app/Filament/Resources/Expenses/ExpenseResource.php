<?php

namespace App\Filament\Resources\Expenses;

use App\Filament\Pages\ShiftMoney\ShiftMoneyPage;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Expenses\Pages\ViewExpense;
use App\Models\ExpenceType;
use App\Models\Expense;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only list of every expense. Adding, editing and deleting happen only on
 * «مصروفات وإيرادات الورديات» (ShiftMoneyPage), so each change is audited and
 * a closed shift's expected cash is recomputed.
 */
class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة المصروفات';

    protected static ?int $navigationSort = 2;

    public static function getModelLabel(): string
    {
        return 'مصروف';
    }

    public static function getPluralModelLabel(): string
    {
        return 'المصروفات';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('expence_type_id')
                    ->label('نوع المصروف')
                    ->options(ExpenceType::query()->pluck('name', 'id'))
                    ->required()
                    ->searchable(),
                TextInput::make('amount')
                    ->label('المبلغ')
                    ->required()
                    ->numeric()
                    ->prefix('ج.م'),
                Textarea::make('notes')
                    ->label('ملاحظات')
                    ->rows(3)
                    ->maxLength(1000),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('shift_id')
                    ->label('الوردية')
                    ->prefix('#')
                    ->sortable(),
                TextColumn::make('expenceType.name')
                    ->label('نوع المصروف')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('amount')
                    ->label('المبلغ')
                    ->money('EGP')
                    ->sortable(),
                TextColumn::make('notes')
                    ->label('ملاحظات')
                    ->limit(50)
                    ->tooltip(function (TextColumn $column): ?string {
                        $state = $column->getState();
                        if (strlen($state) <= 50) {
                            return null;
                        }

                        return $state;
                    }),
                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('تاريخ التحديث')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('expence_type_id')
                    ->label('نوع المصروف')
                    ->options(ExpenceType::query()->pluck('name', 'id')),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('created_from')
                            ->label('من تاريخ'),
                        DatePicker::make('created_until')
                            ->label('إلى تاريخ'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                self::openShiftMoneyAction(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Opens the expense's shift on the shift money page, where it can be changed.
     */
    public static function openShiftMoneyAction(): Action
    {
        return Action::make('openShiftMoney')
            ->label('تعديل من صفحة الوردية')
            ->icon('heroicon-o-pencil-square')
            ->visible(fn (): bool => ShiftMoneyPage::canAccess())
            ->url(fn (Expense $record): string => ShiftMoneyPage::urlForShift((int) $record->shift_id));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenses::route('/'),
            'view' => ViewExpense::route('/{record}'),
        ];
    }
}
