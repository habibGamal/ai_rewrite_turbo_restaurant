<?php

namespace App\Filament\Resources\ManufacturedProducts;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Actions;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Actions\Forms\ProductComponentsImporterAction;
use App\Filament\Components\Forms\ProductComponentSelector;
use App\Models\Product;
use App\Models\Category;
use App\Enums\ProductType;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use \App\Filament\Traits\AdminAccess;
use Filament\Schemas\JsContent;

class ManufacturedProductResource extends Resource
{
    use AdminAccess;

    protected static ?string $model = Product::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة المنتجات';

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

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('معلومات المنتج')
                    ->schema([
                        TextInput::make('name')
                            ->label('اسم المنتج')
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
                            ->default('manufactured'),
                        Toggle::make('legacy')
                            ->label('غير نشط')
                            ->default(false),
                    ])
                    ->columns(3),

                Section::make('مكونات المنتج (الوصفة)')
                    ->schema([
                        Actions::make([
                            ProductComponentsImporterAction::make('importComponents')
                        ])
                            ->alignStart(),

                        ProductComponentSelector::make()
                            ->columnSpanFull(),
                        Repeater::make('productComponents')
                            ->label('المكونات')
                            ->relationship('productComponents', fn($query) => $query->with('component.category'))
                            ->table([
                                TableColumn::make('المكون')
                                    ->width('200px'),
                                TableColumn::make('الكمية')
                                    ->width('100px'),
                                TableColumn::make('الوحدة')
                                    ->width('120px'),
                                TableColumn::make('التكلفة')
                                    ->width('100px'),
                                TableColumn::make('الإجمالي')
                                    ->width('100px'),
                                TableColumn::make('الفئة')
                                    ->width('150px'),
                            ])
                            ->schema([
                                Hidden::make('component_id'),
                                TextInput::make('component_name')
                                    ->label('اسم المكون')
                                    ->formatStateUsing(
                                        fn($record, $state) => $state ?? $record->component->name
                                    )
                                    ->disabled()
                                    ->dehydrated(false),

                                TextInput::make('quantity')
                                    ->label('الكمية')
                                    ->numeric()
                                    ->required()
                                    ->default(1)
                                    ->minValue(0)
                                    ->afterStateUpdatedJs(<<<'JS'
                                        const quantity = parseFloat($state) || 0;
                                        const cost = parseFloat($get('cost')) || 0;
                                        const total = quantity * cost;
                                        $set('total', total);
                                        console.log('Updated total to:', total, $get('../../productComponents'));
                                        let allItems = Object.values($get('../../productComponents') || {});
                                        let overallTotal = 0;
                                        for (let item of allItems) {
                                            overallTotal += parseFloat(item.total) || 0;
                                        }
                                        console.log('Overall total calculated as:', overallTotal);
                                        $set('../../cost', overallTotal); // Trigger parent cost recalculation
                                    JS),

                                TextInput::make('unit')
                                    ->label('الوحدة')
                                    ->formatStateUsing(
                                        fn($record, $state) => $state ?? $record->component->unit
                                    )
                                    ->disabled()
                                    ->dehydrated(false),

                                TextInput::make('cost')
                                    ->label('التكلفة')
                                    ->formatStateUsing(
                                        fn($record, $state) => $state ?? $record->component->cost
                                    )
                                    ->disabled()
                                    ->dehydrated(false),

                                TextInput::make('total')
                                    ->label('الإجمالي')
                                    ->disabled()
                                    ->formatStateUsing(
                                        fn($record, $state, $get) => $state ?? ($record->quantity * $record->component->cost)
                                    )
                                    ->dehydrated(false),

                                TextInput::make('category_name')
                                    ->label('الفئة')
                                    ->formatStateUsing(
                                        fn($record, $state) => $state ?? $record->component->category->name
                                    )
                                    ->disabled()
                                    ->dehydrated(false),
                            ])
                            ->defaultItems(0)
                            ->reorderableWithButtons()
                            ->collapsible()
                            ->itemLabel(fn(array $state): ?string => $state['component_name'] ?? null)
                            ->collapsed()
                            ->compact(),
                    ]),
            ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('اسم المنتج')
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
                    ->formatStateUsing(fn(string $state): string => match ($state) {
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
            'index' => Pages\ListManufacturedProducts::route('/'),
            'create' => Pages\CreateManufacturedProduct::route('/create'),
            'view' => Pages\ViewManufacturedProduct::route('/{record}'),
            'edit' => Pages\EditManufacturedProduct::route('/{record}/edit'),
        ];
    }

}
