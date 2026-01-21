<?php

namespace App\Filament\Resources\ExpenseTypes;

use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\ExpenseTypes\Pages\ListExpenseTypes;
use App\Filament\Resources\ExpenseTypes\Pages\CreateExpenseType;
use App\Filament\Resources\ExpenseTypes\Pages\ViewExpenseType;
use App\Filament\Resources\ExpenseTypes\Pages\EditExpenseType;
use App\Filament\Resources\ExpenseTypeResource\Pages;
use App\Models\ExpenceType;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use \App\Filament\Traits\AdminAccess;

class ExpenseTypeResource extends Resource
{
    use AdminAccess;

    protected static ?string $model = ExpenceType::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-tag';

    protected static string | \UnitEnum | null $navigationGroup = 'إدارة المصروفات';

    protected static ?int $navigationSort = 1;

    public static function getModelLabel(): string
    {
        return 'نوع مصروف';
    }

    public static function getPluralModelLabel(): string
    {
        return 'أنواع المصروفات';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('اسم نوع المصروف')
                    ->required()
                    ->maxLength(255),
                TextInput::make('avg_month_rate')
                    ->label('متوسط الميزانية الشهرية (جنيه)')
                    ->numeric()
                    ->step(0.01)
                    ->suffix('جنيه')
                    ->helperText('متوسط المبلغ المتوقع شهرياً لهذا النوع من المصروفات')
                    ->nullable(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('اسم نوع المصروف')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('avg_month_rate')
                    ->label('متوسط الميزانية الشهرية')
                    ->money('EGP')
                    ->sortable()
                    ->placeholder('غير محدد'),
                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('تاريخ التحديث')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
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
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenseTypes::route('/'),
            'create' => CreateExpenseType::route('/create'),
            'view' => ViewExpenseType::route('/{record}'),
            'edit' => EditExpenseType::route('/{record}/edit'),
        ];
    }
}
