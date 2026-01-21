<?php

namespace App\Filament\Resources\Expenses;

use Filament\Schemas\Schema;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Forms\Components\DatePicker;
use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Expenses\Pages\ViewExpense;
use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Filament\Resources\ExpenseResource\Pages;
use App\Models\Expense;
use App\Models\ExpenceType;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use \App\Filament\Traits\AdminAccess;

class ExpenseResource extends Resource
{
    use AdminAccess;

    protected static ?string $model = Expense::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static string | \UnitEnum | null $navigationGroup = 'إدارة المصروفات';

    protected static ?int $navigationSort = 2;

    public static function getModelLabel(): string
    {
        return 'مصروف';
    }

    public static function getPluralModelLabel(): string
    {
        return 'المصروفات';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('expence_type_id')
                    ->label('نوع المصروف')
                    ->options(ExpenceType::all()->pluck('name', 'id'))
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
                    ->options(ExpenceType::all()->pluck('name', 'id')),
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
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenses::route('/'),
            'create' => CreateExpense::route('/create'),
            'view' => ViewExpense::route('/{record}'),
            'edit' => EditExpense::route('/{record}/edit'),
        ];
    }
}
