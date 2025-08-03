<?php

namespace App\Filament\Resources\PurchaseInvoiceResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'أصناف الفاتورة';

    protected static ?string $label = 'صنف';

    protected static ?string $pluralLabel = 'أصناف';

    public function form(Form $form): Form
    {
        return $form
            ->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('product.name')
            ->columns([
                TextColumn::make('product.name')
                    ->label('المنتج')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('quantity')
                    ->label('الكمية')
                    ->sortable(),

                TextColumn::make('price')
                    ->label('سعر الوحدة')
                    ->money('EGP')
                    ->sortable(),

                TextColumn::make('total')
                    ->label('الإجمالي')
                    ->money('EGP')
                    ->sortable(),
            ]);
    }

    public function isReadOnly(): bool
    {
        return !is_null($this->ownerRecord->closed_at);
    }
}
