<?php

namespace App\Filament\Resources;

use App\Enums\ProductType;
use App\Filament\Resources\PrinterResource\Pages;
use App\Models\Printer;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PrinterResource extends Resource
{
    protected static ?string $model = Printer::class;

    protected static ?string $navigationIcon = 'heroicon-o-printer';

    protected static ?string $navigationGroup = 'إدارة المطعم';

    protected static ?int $navigationSort = 1;

    public static function getModelLabel(): string
    {
        return 'طابعة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الطابعات';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('اسم الطابعة')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('ip_address')
                    ->label('عنوان IP')
                    ->helperText(
                        'أدخل عنوان IP بصيغة صحيحة أو //ip/printerName للطابعة المشتركة عبر USB'
                    )
                    ->maxLength(255),

                CheckboxList::make('categories')
                    ->label('اختر بالفئات ')
                    ->options(
                        \App\Models\Category::all()->pluck('name', 'id')
                    )
                    ->afterStateUpdated(function (array $state, callable $set) {
                        $set(
                            'products',
                            Product::whereIn('category_id', $state)
                                ->whereIn('type', [ProductType::Consumable, ProductType::Manufactured])
                                ->with('category')
                                ->orderBy('category_id')
                                ->pluck('id')
                                ->toArray()
                        );
                    })
                    ->bulkToggleable()
                    ->reactive()
                    ->dehydrated(false)
                    ->columns(3),

                CheckboxList::make('products')
                    ->label('المنتجات المرتبطة')
                    ->relationship(
                        'products',
                        'name',
                        function ($query) {
                            return $query->whereIn('type', [ProductType::Consumable, ProductType::Manufactured])
                                ->with('category')
                                ->orderBy('category_id');
                        }
                    )
                    ->getOptionLabelFromRecordUsing(fn(Product $product) => "$product->name ({$product->category?->name})")
                    ->bulkToggleable()
                    ->columns(3)
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('اسم الطابعة')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('ip_address')
                    ->label('عنوان IP')
                    ->sortable(),
                Tables\Columns\TextColumn::make('products_count')
                    ->label('عدد المنتجات')
                    ->counts('products')
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
            'index' => Pages\ListPrinters::route('/'),
            'create' => Pages\CreatePrinter::route('/create'),
            'view' => Pages\ViewPrinter::route('/{record}'),
            'edit' => Pages\EditPrinter::route('/{record}/edit'),
        ];
    }
}
