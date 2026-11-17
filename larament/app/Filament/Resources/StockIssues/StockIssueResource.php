<?php

namespace App\Filament\Resources\StockIssues;

use App\Enums\ProductType;
use App\Filament\Actions\ConfirmStockIssueAction;
use App\Filament\Actions\PrintLabelsAction;
use App\Filament\Components\Forms\DocumentBarcodeField;
use App\Filament\Resources\StockIssues\Pages\CreateStockIssue;
use App\Filament\Resources\StockIssues\Pages\EditStockIssue;
use App\Filament\Resources\StockIssues\Pages\ListStockIssues;
use App\Filament\Resources\StockIssues\Pages\ViewStockIssue;
use App\Models\Product;
use App\Models\StockIssue;
use App\Models\StockIssueItem;
use App\Models\User;
use App\Models\Warehouse;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
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
        $stockProductOptions = fn (): array => Product::query()
            ->whereIn('type', [
                ProductType::RawMaterial->value,
                ProductType::Consumable->value,
            ])
            ->pluck('name', 'id')
            ->all();

        return $schema
            ->components([
                Section::make('بيانات إذن الصرف')
                    ->schema([
                        Select::make('warehouse_id')
                            ->label('المخزن')
                            ->options(fn (): array => Warehouse::active()->pluck('name', 'id')->all())
                            ->searchable()
                            ->required()
                            ->default(fn (): int => Warehouse::default()->id),

                        TextInput::make('destination')
                            ->label('جهة الصرف')
                            ->required()
                            ->maxLength(255)
                            ->datalist(fn (): array => StockIssue::distinctDestinations())
                            ->dehydrateStateUsing(fn (?string $state): string => trim((string) $state)),

                        Select::make('user_id')
                            ->label('المستخدم')
                            ->options(fn (): array => User::query()->pluck('name', 'id')->all())
                            ->searchable()
                            ->required()
                            ->default(fn (): ?int => auth()->id()),

                        TextInput::make('total_cost')
                            ->label('إجمالي التكلفة (ج.م)')
                            ->numeric()
                            ->prefix('ج.م')
                            ->disabled()
                            ->dehydrated(false)
                            ->default(0),

                        Textarea::make('notes')
                            ->label('ملاحظات')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('أصناف الصرف')
                    ->schema([
                        Repeater::make('items')
                            ->label('الأصناف')
                            ->relationship('items', fn ($query) => $query->with('product'))
                            ->minItems(1)
                            ->defaultItems(1)
                            ->disabled(fn (?StockIssue $record): bool => $record !== null && $record->closed_at !== null)
                            ->schema([
                                Select::make('product_id')
                                    ->label('المنتج')
                                    ->options($stockProductOptions)
                                    ->searchable()
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                        $cost = $state ? (float) (Product::whereKey($state)->value('cost') ?? 0) : 0.0;
                                        $quantity = (float) ($get('quantity') ?? 1);
                                        $set('unit_cost', $cost);
                                        $set('total_cost', round($quantity * $cost, 2));
                                        DocumentBarcodeField::fill($set, $state);
                                    }),

                                ...DocumentBarcodeField::whenEnabled(),

                                TextInput::make('quantity')
                                    ->label('الكمية')
                                    ->numeric()
                                    ->step(0.001)
                                    ->minValue(0.001)
                                    ->default(1)
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                        $productId = $get('product_id');
                                        $cost = $productId ? (float) (Product::whereKey($productId)->value('cost') ?? 0) : 0.0;
                                        $quantity = (float) ($state ?? 0);
                                        $set('unit_cost', $cost);
                                        $set('total_cost', round($quantity * $cost, 2));
                                    }),

                                TextInput::make('unit_cost')
                                    ->label('تكلفة الوحدة الحالية (ج.م)')
                                    ->numeric()
                                    ->prefix('ج.م')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->formatStateUsing(function (?StockIssueItem $record, callable $get): float {
                                        if ($record !== null && $record->stockIssue !== null && $record->stockIssue->closed_at !== null) {
                                            return (float) $record->unit_cost;
                                        }
                                        if ($record !== null && $record->product !== null) {
                                            return (float) $record->product->cost;
                                        }
                                        $productId = $get('product_id');

                                        return $productId ? (float) (Product::whereKey($productId)->value('cost') ?? 0) : 0.0;
                                    }),

                                TextInput::make('total_cost')
                                    ->label('الإجمالي (ج.م)')
                                    ->numeric()
                                    ->prefix('ج.م')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->formatStateUsing(function (?StockIssueItem $record, callable $get): float {
                                        if ($record !== null && $record->stockIssue !== null && $record->stockIssue->closed_at !== null) {
                                            return (float) $record->total_cost;
                                        }
                                        $quantity = (float) ($record !== null ? $record->quantity : ($get('quantity') ?? 0));
                                        $unitCost = $record !== null && $record->product !== null
                                            ? (float) $record->product->cost
                                            : ($get('product_id') ? (float) (Product::whereKey($get('product_id'))->value('cost') ?? 0) : 0.0);

                                        return round($quantity * $unitCost, 2);
                                    }),
                            ])
                            ->columns(4)
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
