<?php

namespace App\Filament\Resources;

use App\Filament\Actions\Forms\ProductComponentsImporterAction;
use App\Filament\Resources\ManufacturedProductResource\Pages;
use App\Models\Product;
use App\Models\Category;
use App\Models\Printer;
use App\Enums\ProductType;
use Awcodes\TableRepeater\Components\TableRepeater;
use Awcodes\TableRepeater\Header;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Actions;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use \App\Filament\Traits\AdminAccess;

class ManufacturedProductResource extends Resource
{
    use AdminAccess;

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
                Section::make('معلومات المنتج')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('اسم المنتج')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('barcode')
                            ->label('الباركود')
                            ->maxLength(255)
                            ->placeholder('اختياري'),
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
                        Forms\Components\TextInput::make('min_stock')
                            ->label('الحد الأدنى للمخزون')
                            ->required()
                            ->numeric()
                            ->default(0),
                        Forms\Components\Select::make('unit')
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
                        Forms\Components\Select::make('printers')
                            ->label('الطابعات')
                            ->relationship('printers', 'name')
                            ->multiple()
                            ->searchable()
                            ->preload(),
                        Forms\Components\Hidden::make('type')
                            ->default('manufactured'),
                        Forms\Components\Toggle::make('legacy')
                            ->label('غير نشط')
                            ->default(false),
                    ])
                    ->columns(3),

                Section::make('مكونات المنتج (الوصفة)')
                    ->extraAttributes([
                        'x-init' => <<<JS
                                const updateTotal = () => {
                                    let items = \$wire.data.productComponents;
                                    if (!Array.isArray(items)) {
                                        items = Object.values(items);
                                    }
                                    \$wire.data.cost = items.reduce((total, item) => total + (item.quantity * item.cost || 0), 0);
                                    items.forEach(item => {
                                        item.total = item.quantity * item.cost || 0;
                                    });
                                };
                                \$watch('\$wire.data', value => {
                                    updateTotal();
                                })
                                updateTotal();
                            JS
                    ])
                    ->schema([
                        Actions::make([
                            ProductComponentsImporterAction::make('importComponents')
                        ])
                            ->alignStart(),

                        TableRepeater::make('productComponents')
                            ->label('المكونات')
                            ->relationship('productComponents', fn($query) => $query->with('component.category'))
                            ->headers([
                                Header::make('component_id')->label('المكون')->width('300px'),
                                Header::make('quantity')->label('الكمية')->width('120px'),
                                Header::make('cost')->label('التكلفة')->width('120px'),
                                Header::make('unit')->label('الوحدة')->width('120px'),
                                Header::make('total')->label('الإجمالي')->width('120px'),
                                Header::make('category_name')->label('الفئة')->width('200px'),

                            ])
                            ->schema([
                                Forms\Components\Hidden::make('component_id'),
                                Forms\Components\TextInput::make('component_name')
                                    ->label('اسم المكون')
                                    ->formatStateUsing(
                                        fn($record, $state) => $state ?? $record->component->name
                                    )
                                    ->disabled()
                                    ->dehydrated(false),

                                Forms\Components\TextInput::make('quantity')
                                    ->label('الكمية')
                                    ->numeric()
                                    ->required()
                                    ->default(1)
                                    ->minValue(0),

                                Forms\Components\TextInput::make('cost')
                                    ->label('التكلفة')
                                    ->formatStateUsing(
                                        fn($record, $state) => $state ?? $record->component->cost
                                    )
                                    ->disabled()
                                    ->dehydrated(false),

                                Forms\Components\TextInput::make('unit')
                                    ->label('الوحدة')
                                    ->formatStateUsing(
                                        fn($record, $state) => $state ?? $record->component->unit
                                    )
                                    ->disabled()
                                    ->dehydrated(false),

                                Forms\Components\TextInput::make('total')
                                    ->label('الإجمالي')
                                    ->disabled()
                                    ->dehydrated(false),

                                Forms\Components\TextInput::make('category_name')
                                    ->label('التكلفة')
                                    ->formatStateUsing(
                                        fn($record, $state) => $state ?? $record->component->category->name
                                    )
                                    ->disabled()
                                    ->dehydrated(false),
                            ])
                            // ->columns(3)
                            ->defaultItems(0)
                            ->reorderableWithButtons()
                            ->collapsible(),
                    ]),
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
                Tables\Columns\TextColumn::make('barcode')
                    ->label('الباركود')
                    ->searchable()
                    ->placeholder('غير محدد'),
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
                Tables\Columns\TextColumn::make('min_stock')
                    ->label('الحد الأدنى للمخزون')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('unit')
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
                Tables\Columns\TextColumn::make('printers.name')
                    ->label('الطابعات')
                    ->badge()
                    ->separator(',')
                    ->sortable(),
                Tables\Columns\IconColumn::make('legacy')
                    ->label('غير نشط')
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
                Tables\Filters\SelectFilter::make('printers')
                    ->label('الطابعة')
                    ->relationship('printers', 'name')
                    ->multiple(),
                Tables\Filters\TernaryFilter::make('legacy')
                    ->label('غير نشط'),
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
