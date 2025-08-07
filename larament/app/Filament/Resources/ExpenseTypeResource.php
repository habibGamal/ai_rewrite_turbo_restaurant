<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ExpenseTypeResource\Pages;
use App\Models\ExpenceType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use \App\Filament\Traits\AdminAccess;

class ExpenseTypeResource extends Resource
{
    use AdminAccess;

    protected static ?string $model = ExpenceType::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'إدارة المصروفات';

    protected static ?int $navigationSort = 1;

    public static function getModelLabel(): string
    {
        return 'نوع مصروف';
    }

    public static function getPluralModelLabel(): string
    {
        return 'أنواع المصروفات';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('اسم نوع المصروف')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('اسم نوع المصروف')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('تاريخ التحديث')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExpenseTypes::route('/'),
            'create' => Pages\CreateExpenseType::route('/create'),
            'view' => Pages\ViewExpenseType::route('/{record}'),
            'edit' => Pages\EditExpenseType::route('/{record}/edit'),
        ];
    }
}
