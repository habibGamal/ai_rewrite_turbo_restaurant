<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ManufacturedProductResource\Pages;
use App\Models\Product;
use App\Models\Category;
use App\Models\Printer;
use App\Enums\ProductType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ManufacturedProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'إدارة المنتجات';

    protected static ?int $navigationSort = 2;

    public static function getModelLabel(): string
    {
        return 'منتج مُصنع';
    }

    public static function getPluralModelLabel(): string
    {
        return 'المنتجات المُصنعة';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('type', ProductType::Manufactured->value);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('اسم المنتج')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('category_id')
                    ->label('الفئة')
                    ->options(Category::all()->pluck('name', 'id'))
                    ->required()
                    ->searchable(),
                Forms\Components\TextInput::make('price')
                    ->label('السعر')
                    ->required()
                    ->numeric()
                    ->prefix('ج.م'),
                Forms\Components\TextInput::make('cost')
                    ->label('التكلفة')
                    ->required()
                    ->numeric()
                    ->prefix('ج.م'),
                Forms\Components\Select::make('unit')
                    ->label('الوحدة')
                    ->options([
                        'packet' => 'حزمة',
                        'kg' => 'كيلوجرام',
                    ])
                    ->required(),
                Forms\Components\Select::make('printer_id')
                    ->label('الطابعة')
                    ->options(Printer::all()->pluck('name', 'id'))
                    ->required()
                    ->searchable(),
                Forms\Components\Hidden::make('type')
                    ->default('manufactured'),
                Forms\Components\Toggle::make('legacy')
                    ->label('منتج قديم')
                    ->default(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('اسم المنتج')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('category.name')
                    ->label('الفئة')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('price')
                    ->label('السعر')
                    ->money('EGP')
                    ->sortable(),
                Tables\Columns\TextColumn::make('cost')
                    ->label('التكلفة')
                    ->money('EGP')
                    ->sortable(),
                Tables\Columns\TextColumn::make('unit')
                    ->label('الوحدة')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'packet' => 'حزمة',
                        'kg' => 'كيلوجرام',
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('printer.name')
                    ->label('الطابعة')
                    ->sortable(),
                Tables\Columns\IconColumn::make('legacy')
                    ->label('منتج قديم')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category_id')
                    ->label('الفئة')
                    ->options(Category::all()->pluck('name', 'id')),
                Tables\Filters\SelectFilter::make('printer_id')
                    ->label('الطابعة')
                    ->options(Printer::all()->pluck('name', 'id')),
                Tables\Filters\TernaryFilter::make('legacy')
                    ->label('منتج قديم'),
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
            'index' => Pages\ListManufacturedProducts::route('/'),
            'create' => Pages\CreateManufacturedProduct::route('/create'),
            'view' => Pages\ViewManufacturedProduct::route('/{record}'),
            'edit' => Pages\EditManufacturedProduct::route('/{record}/edit'),
        ];
    }
}
