<?php

namespace App\Filament\Resources\StockIssues;

use App\Filament\Actions\ConfirmStockIssueAction;
use App\Filament\Actions\Forms\ProductImporterAction;
use App\Filament\Actions\PrintLabelsAction;
use App\Filament\Components\Forms\BarcodeScanInput;
use App\Filament\Components\Forms\DocumentBarcodeField;
use App\Filament\Components\Forms\ProductSelector;
use App\Filament\Resources\StockIssues\Pages\CreateStockIssue;
use App\Filament\Resources\StockIssues\Pages\EditStockIssue;
use App\Filament\Resources\StockIssues\Pages\ListStockIssues;
use App\Filament\Resources\StockIssues\Pages\ViewStockIssue;
use App\Models\Product;
use App\Models\StockIssue;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Products\ProductLookupResult;
use App\Services\Resources\StockIssueCalculatorService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
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
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class StockIssueResource extends Resource
{
    protected static ?string $model = StockIssue::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-right-start-on-rectangle';

    protected static ?string $navigationLabel = 'أذون الصرف';

    protected static ?string $modelLabel = 'إذن صرف';

    protected static ?string $pluralModelLabel = 'أذون الصرف';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة المخزون';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('معلومات إذن الصرف')
                    ->schema([
                        Select::make('warehouse_id')
                            ->label('المخزن')
                            ->options(fn (): array => Warehouse::active()->pluck('name', 'id')->all())
                            ->searchable()
                            ->required()
                            ->default(fn (): int => Warehouse::default()->id)
                            ->disabled(fn (?StockIssue $record): bool => $record?->closed_at !== null),

                        TextInput::make('destination')
                            ->label('جهة الصرف')
                            ->required()
                            ->maxLength(255)
                            ->datalist(fn (): array => StockIssue::distinctDestinations())
                            ->dehydrateStateUsing(fn (?string $state): string => trim((string) $state))
                            ->disabled(fn (?StockIssue $record): bool => $record?->closed_at !== null),

                        Select::make('user_id')
                            ->label('المستخدم')
                            ->options(fn (): array => User::query()->pluck('name', 'id')->all())
                            ->searchable()
                            ->required()
                            ->default(fn (): ?int => auth()->id())
                            ->disabled(fn (?StockIssue $record): bool => $record?->closed_at !== null),

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
                            ->disabled(fn (?StockIssue $record): bool => $record?->closed_at !== null),
                    ])
                    ->columns(2),

                Section::make('أصناف الصرف')
                    ->extraAttributes([
                        'x-init' => StockIssueCalculatorService::getJavaScriptCalculation(),
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
                            ->disabled(fn (?StockIssue $record): bool => $record?->closed_at !== null)
                            ->mutateRelationshipDataBeforeCreateUsing(function ($data) {
                                return StockIssueCalculatorService::prepareItemData($data);
                            })
                            ->mutateRelationshipDataBeforeSaveUsing(function ($data) {
                                return StockIssueCalculatorService::prepareItemData($data);
                            })
                            ->collapsible(),
                    ]),
            ])
            ->columns(1);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('بيانات إذن الصرف')
                    ->schema([
                        TextEntry::make('id')
                            ->label('رقم الإذن'),

                        TextEntry::make('warehouse.name')
                            ->label('المخزن'),

                        TextEntry::make('destination')
                            ->label('جهة الصرف'),

                        TextEntry::make('user.name')
                            ->label('المستخدم'),

                        TextEntry::make('total_cost')
                            ->label('إجمالي التكلفة')
                            ->money('EGP'),

                        TextEntry::make('closed_at')
                            ->label('الحالة')
                            ->formatStateUsing(fn ($state): string => $state ? 'مؤكد' : 'مسودة')
                            ->badge()
                            ->color(fn ($state): string => $state ? 'success' : 'warning'),

                        TextEntry::make('created_at')
                            ->label('تاريخ الإنشاء')
                            ->dateTime('d/m/Y H:i'),

                        TextEntry::make('notes')
                            ->label('ملاحظات')
                            ->columnSpanFull(),
                    ])
                    ->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('رقم الإذن')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('warehouse.name')
                    ->label('المخزن')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('destination')
                    ->label('جهة الصرف')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('user.name')
                    ->label('المستخدم')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('items_count')
                    ->label('عدد الأصناف')
                    ->counts('items')
                    ->sortable(),

                TextColumn::make('total_cost')
                    ->label('إجمالي التكلفة')
                    ->money('EGP')
                    ->sortable(),

                TextColumn::make('closed_at')
                    ->label('الحالة')
                    ->formatStateUsing(fn ($state): string => $state ? 'مؤكد' : 'مسودة')
                    ->badge()
                    ->color(fn ($state): string => $state ? 'success' : 'warning')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('warehouse_id')
                    ->label('المخزن')
                    ->relationship('warehouse', 'name'),

                SelectFilter::make('user_id')
                    ->label('المستخدم')
                    ->relationship('user', 'name'),

                Filter::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->schema([
                        DatePicker::make('created_from')
                            ->label('من تاريخ'),
                        DatePicker::make('created_until')
                            ->label('إلى تاريخ'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),

                TernaryFilter::make('closed_at')
                    ->label('الحالة')
                    ->placeholder('جميع الحالات')
                    ->trueLabel('مؤكد')
                    ->falseLabel('مسودة')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('closed_at'),
                        false: fn (Builder $query) => $query->whereNull('closed_at'),
                        blank: fn (Builder $query) => $query,
                    ),
            ])
            ->recordActions([
                ConfirmStockIssueAction::table(),
                PrintLabelsAction::document(),
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getEditAuthorizationResponse(Model $record): Response
    {
        return ($record instanceof StockIssue && is_null($record->closed_at) && parent::getEditAuthorizationResponse($record)->allowed()) ? Response::allow() : Response::deny();
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return ($record instanceof StockIssue && is_null($record->closed_at) && parent::getDeleteAuthorizationResponse($record)->allowed()) ? Response::allow() : Response::deny();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockIssues::route('/'),
            'create' => CreateStockIssue::route('/create'),
            'view' => ViewStockIssue::route('/{record}'),
            'edit' => EditStockIssue::route('/{record}/edit'),
        ];
    }
}
