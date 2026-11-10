<?php

namespace App\Filament\Resources\ExternalSellers\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'products';

    protected static ?string $title = 'الأصناف';

    protected static ?string $modelLabel = 'صنف';

    protected static ?string $pluralModelLabel = 'أصناف';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('الاسم')->required()->maxLength(255)
                ->unique(modifyRuleUsing: fn ($rule) => $rule->where('external_seller_id', $this->getOwnerRecord()->getKey()), ignoreRecord: true),
            TextInput::make('price')->label('السعر')->required()->numeric()->minValue(0)->maxValue(9999999999.99)->step(0.01),
            Toggle::make('is_active')->label('نشط')->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('الاسم')->searchable()->sortable(),
            TextColumn::make('price')->label('السعر')->money('EGP'),
            IconColumn::make('is_active')->label('نشط')->boolean(),
        ])->headerActions([CreateAction::make()])->recordActions([EditAction::make()])->defaultSort('name');
    }
}
