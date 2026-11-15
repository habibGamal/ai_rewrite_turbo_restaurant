<?php

namespace App\Filament\Resources\ConsumableProducts;

use App\Enums\ProductType;
use App\Filament\Components\Forms\KitchenDisplayFields;
use App\Filament\Components\Forms\ProductBarcodeFields;
use App\Filament\Resources\ConsumableProducts\Pages\CreateConsumableProduct;
use App\Filament\Resources\ConsumableProducts\Pages\EditConsumableProduct;
use App\Filament\Resources\ConsumableProducts\Pages\ListConsumableProducts;
use App\Filament\Resources\ConsumableProducts\Pages\ViewConsumableProduct;
use App\Filament\Resources\ProductGroups\ProductGroupResource;
use App\Models\Category;
use App\Models\Product;
use App\Services\FeatureService;
use App\Services\Products\ProductVariantService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ConsumableProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة المنتجات';

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
                TextEntry::make('variant_group_link')
                    ->label('الموديل')
                    ->state(fn (?Product $record): ?string => $record?->group?->name)
                    ->helperText('هذا المنتج مقاس/لون من موديل؛ عدّل السعر والمتغيرات من صفحة الموديل.')
                    ->url(fn (?Product $record): ?string => ($record?->product_group_id !== null && ProductGroupResource::canAccess())
                        ? ProductGroupResource::getUrl('edit', ['record' => $record->product_group_id])
                        : null)
                    ->color('primary')
                    ->visible(fn (?Product $record): bool => $record?->product_group_id !== null),
                TextInput::make('name')
                    ->label('اسم المنتج الاستهلاكي')
                    ->required()
                    ->maxLength(255),
                ProductBarcodeFields::primary(),
                ProductBarcodeFields::extra(),
                Select::make('category_id')
                    ->label('الفئة')
                    ->options(Category::query()->pluck('name', 'id'))
                    ->required()
                    ->searchable(),
                Select::make('warehouse_id')
                    ->label('مخزن السحب')
                    ->relationship(
                        'warehouse',
                        'name',
                        fn (Builder $query): Builder => $query->where('is_active', true),
                    )
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->placeholder('حسب التصنيف')
                    ->helperText('اختياري. إن تُرك فارغاً يُسحب المخزون من مخزن التصنيف الرئيسي، وإلا من المخزن الافتراضي.'),
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
                    // Variants of a retail group can never be weighed (scale) items.
                    ->disableOptionWhen(fn (string $value, ?Product $record): bool => $record?->product_group_id !== null
                        && in_array($value, ProductVariantService::WEIGHT_UNITS, true))
                    ->rule(
                        'not_in:'.implode(',', ProductVariantService::WEIGHT_UNITS),
                        fn (?Product $record): bool => $record?->product_group_id !== null,
                    )
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
                ...KitchenDisplayFields::components(),
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
                    ->options(Category::query()->pluck('name', 'id')),
                SelectFilter::make('printers')
                    ->label('الطابعة')
                    ->relationship('printers', 'name')
                    ->multiple(),
                TernaryFilter::make('legacy')
                    ->label('غير نشط'),
                // Variant SKUs are managed from their group (الموديلات); hide them here by default.
                TernaryFilter::make('variants')
                    ->label('مقاسات/ألوان الموديلات')
                    ->placeholder('الكل')
                    ->trueLabel('المتغيرات فقط')
                    ->falseLabel('بدون المتغيرات')
                    ->default(false)
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('product_group_id'),
                        false: fn (Builder $query) => $query->whereNull('product_group_id'),
                        blank: fn (Builder $query) => $query,
                    )
                    ->visible(fn (): bool => app(FeatureService::class)->variantsEnabled()),
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
