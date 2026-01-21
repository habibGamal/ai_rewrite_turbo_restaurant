<?php

namespace App\Filament\Resources\Wastes;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Actions;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\Wastes\Pages\ListWastes;
use App\Filament\Resources\Wastes\Pages\CreateWaste;
use App\Filament\Resources\Wastes\Pages\ViewWaste;
use App\Filament\Resources\Wastes\Pages\EditWaste;
use App\Filament\Actions\Forms\ProductImporterAction;
use App\Filament\Actions\CloseWasteAction;
use App\Filament\Actions\PrintWasteAction;
use App\Filament\Components\Forms\ProductSelector;
use App\Filament\Resources\WasteResource\Pages;
use App\Models\Waste;
use App\Models\Product;
use App\Models\User;
use App\Services\Resources\WasteCalculatorService;
use App\Enums\ProductType;
use Filament\Forms;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use \App\Filament\Traits\AdminAccess;

class WasteResource extends Resource
{
    use AdminAccess;

    protected static ?string $model = Waste::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-trash';

    protected static ?string $navigationLabel = 'التالف';

    protected static ?string $modelLabel = 'سجل تالف';

    protected static ?string $pluralModelLabel = 'سجلات التالف';

    protected static string | \UnitEnum | null $navigationGroup = 'إدارة المخزون';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('معلومات سجل التالف')
                    ->schema([
                        Select::make('user_id')
                            ->label('المستخدم')
                            ->options(User::all()->pluck('name', 'id'))
                            ->searchable()
                            ->required()
                            ->default(auth()->id()),

                        TextInput::make('total')
                            ->label('إجمالي قيمة التالف (ج.م)')
                            ->numeric()
                            ->prefix('ج.م')
                            ->disabled()
                            ->dehydrated(false)
                            ->default(0),

                        Textarea::make('notes')
                            ->label('ملاحظات')
                            ->required(false)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('الأصناف التالفة')
                    ->extraAttributes([
                        "x-init" => WasteCalculatorService::getJavaScriptCalculation(),
                    ])
                    ->schema([
                        Actions::make([
                            ProductImporterAction::make('importProducts')
                            ->additionalProps(function (Product $product) {
                                return [
                                    'stock_quantity' => $product->inventoryItem?->quantity ?? 0,
                                ];
                            }),
                        ])
                            ->alignStart(),

                        ProductSelector::make()
                            ->columnSpanFull(),
                        Repeater::make('items')
                            ->label('الأصناف')
                            ->relationship('items', fn($query) => $query->with('product.inventoryItem'))
                            ->table([
                                TableColumn::make('المنتج')
                                    ->width('200px'),
                                TableColumn::make('الكمية الحالية')
                                    ->width('100px'),
                                TableColumn::make('الكمية')
                                    ->width('100px'),
                                TableColumn::make('سعر الوحدة (ج.م)')
                                    ->width('120px'),
                                TableColumn::make('الإجمالي (ج.م)')
                                    ->width('120px'),
                            ])
                            ->schema([
                                Hidden::make('product_id'),

                                TextInput::make('product_name')
                                    ->label('المنتج')
                                    ->formatStateUsing(function ($record, $state) {
                                        return $state ?? ($record->product?->name ?? 'غير محدد');
                                    })
                                    ->dehydrated(false)
                                    ->disabled(),

                                TextInput::make('stock_quantity')
                                    ->label('الكمية الحالية')
                                    ->formatStateUsing(function ($record) {
                                        return ($record->product?->inventoryItem->quantity ?? 'غير محدد');
                                    })
                                    ->disabled(),

                                TextInput::make('quantity')
                                    ->label('الكمية')
                                    ->numeric()
                                    ->required()
                                    ->default(1)
                                    ->minValue(0),


                                TextInput::make('price')
                                    ->label('سعر الوحدة (ج.م)')
                                    ->numeric()
                                    ->prefix('ج.م')
                                    ->disabled()
                                    ->dehydrated(condition: true),

                                TextInput::make('total')
                                    ->label('الإجمالي (ج.م)')
                                    ->numeric()
                                    ->prefix('ج.م')
                                    ->disabled()
                                    ->dehydrated(false),
                            ])
                            ->defaultItems(0)
                            ->reorderableWithButtons()
                            ->dehydrated(true)
                            ->mutateRelationshipDataBeforeCreateUsing(function ($data) {
                                return WasteCalculatorService::prepareItemData($data);
                            })
                            ->mutateRelationshipDataBeforeSaveUsing(function ($data) {
                                return WasteCalculatorService::prepareItemData($data);
                            })
                            ->collapsible(),
                    ]),
            ])->columns(1);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('معلومات سجل التالف')
                    ->schema([
                        TextEntry::make('id')
                            ->label('رقم السجل'),

                        TextEntry::make('user.name')
                            ->label('المستخدم'),

                        TextEntry::make('total')
                            ->label('إجمالي قيمة التالف')
                            ->money('EGP'),

                        TextEntry::make('closed_at')
                            ->label('الحالة')
                            ->formatStateUsing(function ($state) {
                                return $state ? 'مغلق' : 'مفتوح';
                            })
                            ->badge()
                            ->color(fn($state): string => $state ? 'success' : 'warning'),

                        TextEntry::make('created_at')
                            ->label('تاريخ الإنشاء')
                            ->dateTime('d/m/Y H:i'),

                        TextEntry::make('notes')
                            ->label('ملاحظات'),
                    ])
                    ->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('رقم السجل')
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

                TextColumn::make('total')
                    ->label('إجمالي قيمة التالف')
                    ->money('EGP')
                    ->sortable(),

                TextColumn::make('notes')
                    ->label('ملاحظات')
                    ->limit(50)
                    ->tooltip(fn($state): ?string => $state),

                TextColumn::make('closed_at')
                    ->label('الحالة')
                    ->formatStateUsing(function ($state) {
                        return $state ? 'مغلق' : 'مفتوح';
                    })
                    ->badge()
                    ->color(fn($state): string => $state ? 'success' : 'warning')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
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
                                $data['created_from'],
                                fn(Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn(Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),

                TernaryFilter::make('closed_at')
                    ->label('الحالة')
                    ->placeholder('جميع الحالات')
                    ->trueLabel('مغلق')
                    ->falseLabel('مفتوح')
                    ->queries(
                        true: fn(Builder $query) => $query->whereNotNull('closed_at'),
                        false: fn(Builder $query) => $query->whereNull('closed_at'),
                        blank: fn(Builder $query) => $query,
                    ),
            ])
            ->recordActions([
                CloseWasteAction::table(),
                PrintWasteAction::table(),
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

    public static function canEdit(Model $record): bool
    {
        return is_null($record->closed_at);
    }

    public static function canDelete(Model $record): bool
    {
        return is_null($record->closed_at);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWastes::route('/'),
            'create' => CreateWaste::route('/create'),
            'view' => ViewWaste::route('/{record}'),
            'edit' => EditWaste::route('/{record}/edit'),
        ];
    }
}
