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
            ->schema([
                Forms\Components\Select::make('product_id')
                    ->label('المنتج')
                    ->options(Product::all()->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    ->reactive()
                    ->afterStateUpdated(function ($state, callable $set) {
                        if ($state) {
                            $product = Product::find($state);
                            if ($product) {
                                $set('price', $product->cost);
                                $set('product_name', $product->name);
                            }
                        }
                    }),

                Forms\Components\TextInput::make('product_name')
                    ->label('اسم المنتج')
                    ->disabled()
                    ->dehydrated(false),

                Forms\Components\TextInput::make('quantity')
                    ->label('الكمية')
                    ->numeric()
                    ->required()
                    ->default(1)
                    ->minValue(1)
                    ->reactive()
                    ->afterStateUpdated(function ($state, callable $set, callable $get) {
                        $price = $get('price') ?? 0;
                        $set('total', $state * $price);
                    }),

                Forms\Components\TextInput::make('price')
                    ->label('سعر الوحدة (ج.م)')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->prefix('ج.م')
                    ->reactive()
                    ->afterStateUpdated(function ($state, callable $set, callable $get) {
                        $quantity = $get('quantity') ?? 1;
                        $set('total', $state * $quantity);
                    }),

                Forms\Components\TextInput::make('total')
                    ->label('الإجمالي (ج.م)')
                    ->numeric()
                    ->prefix('ج.م')
                    ->disabled()
                    ->dehydrated(),
            ]);
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
