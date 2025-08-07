<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DineTableResource\Pages;
use App\Models\DineTable;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use \App\Filament\Traits\AdminAccess;

class DineTableResource extends Resource
{
    use AdminAccess;

    protected static ?string $model = DineTable::class;

    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationGroup = 'المشاهدة فقط';

    protected static ?int $navigationSort = 1;

    public static function getModelLabel(): string
    {
        return 'طاولة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الطاولات';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                //
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->label('رقم الطاولة')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('الحالة')
                    ->badge(),
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
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                // No bulk actions for read-only resource
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDineTables::route('/'),
            'view' => Pages\ViewDineTable::route('/{record}'),
        ];
    }
}
