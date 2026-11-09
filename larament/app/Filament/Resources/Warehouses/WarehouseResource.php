<?php

namespace App\Filament\Resources\Warehouses;

use App\Filament\Resources\Warehouses\Pages\CreateWarehouse;
use App\Filament\Resources\Warehouses\Pages\EditWarehouse;
use App\Filament\Resources\Warehouses\Pages\ListWarehouses;
use App\Models\Warehouse;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class WarehouseResource extends Resource
{
    protected static ?string $model = Warehouse::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'المخازن';

    protected static ?string $modelLabel = 'مخزن';

    protected static ?string $pluralModelLabel = 'المخازن';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة المخزون';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('اسم المخزن')->required()->maxLength(255),
            Toggle::make('is_default')->label('المخزن الافتراضي')
                ->disabled(fn (?Warehouse $record): bool => $record !== null && $record->is_default),
            Toggle::make('is_active')->label('نشط')->default(true)
                ->disabled(fn (?Warehouse $record): bool => $record !== null && $record->is_default),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('اسم المخزن')->searchable()->sortable(),
            IconColumn::make('is_default')->label('افتراضي')->boolean(),
            IconColumn::make('is_active')->label('نشط')->boolean(),
        ])->filters([
            TernaryFilter::make('is_active')->label('نشط'),
        ])->recordActions([
            EditAction::make(),
            DeleteAction::make()->visible(fn (Warehouse $record): bool => ! $record->is_default && ! $record->movements()->exists()),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWarehouses::route('/'),
            'create' => CreateWarehouse::route('/create'),
            'edit' => EditWarehouse::route('/{record}/edit'),
        ];
    }
}
