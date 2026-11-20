<?php

namespace App\Filament\Resources\StockTransfers;

use App\Filament\Actions\ConfirmStockTransferAction;
use App\Filament\Actions\Forms\ProductImporterAction;
use App\Filament\Actions\PrintLabelsAction;
use App\Filament\Components\Forms\BarcodeScanInput;
use App\Filament\Components\Forms\DocumentBarcodeField;
use App\Filament\Components\Forms\ProductSelector;
use App\Filament\Resources\StockTransfers\Pages\CreateStockTransfer;
use App\Filament\Resources\StockTransfers\Pages\EditStockTransfer;
use App\Filament\Resources\StockTransfers\Pages\ListStockTransfers;
use App\Filament\Resources\StockTransfers\Pages\ViewStockTransfer;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Products\ProductLookupResult;
use App\Services\Resources\StockTransferCalculatorService;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

class StockTransferResource extends Resource
{
    protected static ?string $model = StockTransfer::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationLabel = 'أذون التحويل';

    protected static ?string $modelLabel = 'إذن تحويل';

    protected static ?string $pluralModelLabel = 'أذون التحويل';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة المخزون';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('معلومات إذن التحويل')
                ->schema([
                    Select::make('from_warehouse_id')
                        ->label('من مخزن')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->options(fn () => Warehouse::active()->pluck('name', 'id')->all())
                        ->default(fn () => Warehouse::default()->id)
                        ->disabled(fn (?StockTransfer $record): bool => $record?->closed_at !== null),

                    Select::make('to_warehouse_id')
                        ->label('إلى مخزن')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->different('from_warehouse_id')
                        ->options(fn () => Warehouse::active()->pluck('name', 'id')->all())
                        ->disabled(fn (?StockTransfer $record): bool => $record?->closed_at !== null),

                    Select::make('user_id')
                        ->label('المستخدم')
                        ->required()
                        ->searchable()
                        ->options(fn () => User::query()->pluck('name', 'id')->all())
                        ->default(fn () => auth()->id())
                        ->disabled(fn (?StockTransfer $record): bool => $record?->closed_at !== null),

                    TextInput::make('total_cost')
                        ->label('إجمالي التكلفة (ج.م)')
                        ->numeric()
                        ->prefix('ج.م')
                        ->disabled()
                        ->dehydrated(false)
                        ->default(0),

                    Textarea::make('notes')
                        ->label('ملاحظات')
                        ->required(false)
                        ->columnSpanFull()
                        ->disabled(fn (?StockTransfer $record): bool => $record?->closed_at !== null),
                ])
                ->columns(2),

            Section::make('أصناف التحويل')
                ->extraAttributes([
                    'x-init' => StockTransferCalculatorService::getJavaScriptCalculation(),
                ])
                ->schema([
                    Actions::make([
                        ProductImporterAction::make('importProducts')
                            ->additionalProps(function (Product $product) {
                                return [
                                    'stock_quantity' => $product->inventoryItem ? (float) $product->inventoryItem->quantity : 0.0,
                                ];
                            }),
                    ])
                        ->alignStart(),

                    BarcodeScanInput::make()
                        ->onScanned(fn (ProductLookupResult $result, Set $set, Get $get) => ProductSelector::addScannedProduct($result->product, $result->quantity, $set, $get))
                        ->columnSpanFull(),
                    ProductSelector::make()
                        ->columnSpanFull(),

                    Repeater::make('items')
                        ->label('الأصناف')
                        ->relationship('items', fn ($query) => $query->with('product.inventoryItem'))
                        ->table(fn (): array => array_values(array_filter([
                            TableColumn::make('المنتج')
                                ->width('200px'),
                            DocumentBarcodeField::enabled()
                                ? TableColumn::make('الباركود')->width('140px')
                                : null,
                            TableColumn::make('الوحدة')
                                ->width('100px'),
                            TableColumn::make('الكمية الحالية')
                                ->width('100px'),
                            TableColumn::make('الكمية')
                                ->width('100px'),
                            TableColumn::make('سعر الوحدة (ج.م)')
                                ->width('120px'),
                            TableColumn::make('الإجمالي (ج.م)')
                                ->width('120px'),
                        ])))
                        ->schema([
                            Hidden::make('product_id'),

                            TextInput::make('product_name')
                                ->label('المنتج')
                                ->formatStateUsing(function ($record, $state) {
                                    return $state ?? ($record && $record->product ? $record->product->name : 'غير محدد');
                                })
                                ->dehydrated(false)
                                ->disabled(),

                            ...DocumentBarcodeField::whenEnabled(),

                            TextInput::make('unit')
                                ->label('الوحدة')
                                ->formatStateUsing(function ($record, $state) {
                                    return Product::formatUnit($state ?? ($record && $record->product ? $record->product->unit : null));
                                })
                                ->disabled()
                                ->dehydrated(false),

                            TextInput::make('stock_quantity')
                                ->label('الكمية الحالية')
                                ->formatStateUsing(function ($record) {
                                    return $record && $record->product && $record->product->inventoryItem
                                        ? (string) $record->product->inventoryItem->quantity
                                        : 'غير محدد';
                                })
                                ->disabled(),

                            TextInput::make('quantity')
                                ->label('الكمية')
                                ->numeric()
                                ->step(0.001)
                                ->required()
                                ->default(1)
                                ->minValue(0.001),

                            TextInput::make('unit_cost')
                                ->label('سعر الوحدة (ج.م)')
                                ->numeric()
                                ->prefix('ج.م')
                                ->disabled()
                                ->dehydrated(true)
                                ->formatStateUsing(function ($record, $state) {
                                    if ($state !== null) {
                                        return $state;
                                    }

                                    return $record ? ($record->unit_cost ?? ($record->product ? $record->product->cost : 0)) : 0;
                                }),

                            TextInput::make('total_cost')
                                ->label('الإجمالي (ج.م)')
                                ->numeric()
                                ->prefix('ج.م')
                                ->disabled()
                                ->dehydrated(false)
                                ->formatStateUsing(function ($record, $state) {
                                    if ($state !== null) {
                                        return $state;
                                    }

                                    return $record ? ($record->total_cost ?? 0) : 0;
                                }),
                        ])
                        ->defaultItems(0)
                        ->reorderableWithButtons()
                        ->dehydrated(true)
                        ->disabled(fn (?StockTransfer $record): bool => $record?->closed_at !== null)
                        ->mutateRelationshipDataBeforeCreateUsing(function ($data) {
                            return StockTransferCalculatorService::prepareItemData($data);
                        })
                        ->mutateRelationshipDataBeforeSaveUsing(function ($data) {
                            return StockTransferCalculatorService::prepareItemData($data);
                        })
                        ->collapsible(),
                ]),
        ])->columns(1);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('بيانات التحويل')->columns(3)->schema([
                TextEntry::make('fromWarehouse.name')->label('من مخزن'),
                TextEntry::make('toWarehouse.name')->label('إلى مخزن'),
                TextEntry::make('user.name')->label('المستخدم'),
                TextEntry::make('total_cost')->label('إجمالي التكلفة')->money('EGP'),
                TextEntry::make('closed_at')->label('تاريخ التأكيد')->dateTime()->placeholder('مسودة'),
                TextEntry::make('closedByUser.name')->label('أكد بواسطة'),
                TextEntry::make('business_date')->label('يوم العمل')->date(),
                TextEntry::make('notes')->label('ملاحظات')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('رقم التحويل')->sortable(),
            TextColumn::make('fromWarehouse.name')->label('من مخزن')->searchable(),
            TextColumn::make('toWarehouse.name')->label('إلى مخزن')->searchable(),
            TextColumn::make('user.name')->label('المستخدم'),
            TextColumn::make('total_cost')->label('إجمالي التكلفة')->money('EGP')->sortable(),
            TextColumn::make('closed_at')->label('الحالة')->placeholder('مسودة')
                ->formatStateUsing(fn ($state): string => $state ? 'مؤكد' : 'مسودة')->badge(),
            TextColumn::make('business_date')->label('يوم العمل')->date()->sortable(),
        ])->filters([
            SelectFilter::make('from_warehouse_id')->label('من مخزن')->relationship('fromWarehouse', 'name'),
            SelectFilter::make('to_warehouse_id')->label('إلى مخزن')->relationship('toWarehouse', 'name'),
        ])->recordActions([
            ConfirmStockTransferAction::table(), PrintLabelsAction::document(), ViewAction::make(), EditAction::make(), DeleteAction::make(),
        ])->defaultSort('id', 'desc');
    }

    public static function getEditAuthorizationResponse(Model $record): Response
    {
        return ($record instanceof StockTransfer && $record->closed_at === null && parent::getEditAuthorizationResponse($record)->allowed()) ? Response::allow() : Response::deny();
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return ($record instanceof StockTransfer && $record->closed_at === null && parent::getDeleteAuthorizationResponse($record)->allowed()) ? Response::allow() : Response::deny();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockTransfers::route('/'),
            'create' => CreateStockTransfer::route('/create'),
            'view' => ViewStockTransfer::route('/{record}'),
            'edit' => EditStockTransfer::route('/{record}/edit'),
        ];
    }
}
