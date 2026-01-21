<?php

namespace App\Filament\Resources\ConsumableProducts;

use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\ConsumableProducts\Pages\ListConsumableProducts;
use App\Filament\Resources\ConsumableProducts\Pages\CreateConsumableProduct;
use App\Filament\Resources\ConsumableProducts\Pages\ViewConsumableProduct;
use App\Filament\Resources\ConsumableProducts\Pages\EditConsumableProduct;
use App\Filament\Resources\ConsumableProductResource\Pages;
use App\Models\Product;
use App\Models\Category;
use App\Models\Printer;
use App\Enums\ProductType;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Traits\AdminAccess;

class ConsumableProductResource extends Resource
{
    use AdminAccess;

    protected static ?string $model = Product::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static string | \UnitEnum | null $navigationGroup = 'إدارة المنتجات';

    protected static ?int $navigationSort = 4;

    public static function getModelLabel(): string
    {
        return 'منتج استهلاكي';
    }

    public static function getPluralModelLabel(): string
    {
        return 'المنتجات الاستهلاكية';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('type', ProductType::Consumable->value);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('اسم المنتج الاستهلاكي')
                    ->required()
                    ->maxLength(255),
                TextInput::make('barcode')
                    ->label('الباركود')
                    ->maxLength(255)
                    ->placeholder('اختياري'),
                Select::make('category_id')
                    ->label('الفئة')
                    ->options(Category::all()->pluck('name', 'id'))
                    ->required()
                    ->searchable(),
                TextInput::make('price')
                    ->label('السعر')
                    ->required()
                    ->numeric()
                    ->prefix('ج.م'),
                TextInput::make('cost')
                    ->label('التكلفة')
                    ->required()
                    ->numeric()
                    ->prefix('ج.م'),
                TextInput::make('min_stock')
                    ->label('الحد الأدنى للمخزون')
                    ->required()
                    ->numeric()
                    ->default(0),
                Select::make('unit')
                    ->label('الوحدة')
                    ->options([
                        'packet' => 'باكت',
                        'kg' => 'كيلوجرام',
                        'gram' => 'جرام',
                        'liter' => 'لتر',
                        'ml' => 'ميليلتر',
                        'piece' => 'قطعة',
                        'box' => 'صندوق',
                        'bag' => 'كيس',
                        'bottle' => 'زجاجة',
                        'can' => 'علبة',
                        'cup' => 'كوب',
                        'tablespoon' => 'ملعقة كبيرة',
                        'teaspoon' => 'ملعقة صغيرة',
                        'dozen' => 'دستة',
                        'meter' => 'متر',
                        'cm' => 'سنتيمتر',
                        'roll' => 'رول',
                        'sheet' => 'ورقة',
                        'slice' => 'شريحة',
                        'loaf' => 'رغيف',
                    ])
                    ->required(),
                Select::make('printers')
                    ->label('الطابعات')
                    ->relationship('printers', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload(),
                Hidden::make('type')
                    ->default('consumable'),
                Toggle::make('legacy')
                    ->label('غير نشط')
                    ->default(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('اسم المنتج الاستهلاكي')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('barcode')
                    ->label('الباركود')
                    ->searchable()
                    ->placeholder('غير محدد'),
                TextColumn::make('category.name')
                    ->label('الفئة')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('price')
                    ->label('السعر')
                    ->money('EGP')
                    ->sortable(),
                TextColumn::make('cost')
                    ->label('التكلفة')
                    ->money('EGP')
                    ->sortable(),
                TextColumn::make('min_stock')
                    ->label('الحد الأدنى للمخزون')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('unit')
                    ->label('الوحدة')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'packet' => 'باكت',
                        'kg' => 'كيلوجرام',
                        'gram' => 'جرام',
                        'liter' => 'لتر',
                        'ml' => 'ميليلتر',
                        'piece' => 'قطعة',
                        'box' => 'صندوق',
                        'bag' => 'كيس',
                        'bottle' => 'زجاجة',
                        'can' => 'علبة',
                        'cup' => 'كوب',
                        'tablespoon' => 'ملعقة كبيرة',
                        'teaspoon' => 'ملعقة صغيرة',
                        'dozen' => 'دستة',
                        'meter' => 'متر',
                        'cm' => 'سنتيمتر',
                        'roll' => 'رول',
                        'sheet' => 'ورقة',
                        'slice' => 'شريحة',
                        'loaf' => 'رغيف',
                        default => $state,
                    }),
                TextColumn::make('printers.name')
                    ->label('الطابعات')
                    ->badge()
                    ->separator(',')
                    ->sortable(),
                TextColumn::make('inventoryItem.quantity')
                    ->label('المخزون')
                    ->sortable()
                    ->default('0'),
                IconColumn::make('legacy')
                    ->label('غير نشط')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category_id')
                    ->label('الفئة')
                    ->options(Category::all()->pluck('name', 'id')),
                SelectFilter::make('printers')
                    ->label('الطابعة')
                    ->relationship('printers', 'name')
                    ->multiple(),
                TernaryFilter::make('legacy')
                    ->label('غير نشط'),
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
            'index' => ListConsumableProducts::route('/'),
            'create' => CreateConsumableProduct::route('/create'),
            'view' => ViewConsumableProduct::route('/{record}'),
            'edit' => EditConsumableProduct::route('/{record}/edit'),
        ];
    }
}
