<?php

namespace App\Filament\Resources\ReturnPurchaseInvoices;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Actions;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Forms\Components\DatePicker;
use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\ReturnPurchaseInvoices\Pages\ListReturnPurchaseInvoices;
use App\Filament\Resources\ReturnPurchaseInvoices\Pages\CreateReturnPurchaseInvoice;
use App\Filament\Resources\ReturnPurchaseInvoices\Pages\ViewReturnPurchaseInvoice;
use App\Filament\Resources\ReturnPurchaseInvoices\Pages\EditReturnPurchaseInvoice;
use App\Filament\Actions\Forms\ProductImporterAction;
use App\Filament\Actions\CloseReturnPurchaseInvoiceAction;
use App\Filament\Actions\PrintReturnPurchaseInvoiceAction;
use App\Filament\Components\Forms\ProductSelector;
use App\Filament\Resources\ReturnPurchaseInvoiceResource\Pages;
use App\Filament\Resources\ReturnPurchaseInvoiceResource\RelationManagers;
use App\Models\ReturnPurchaseInvoice;
use App\Models\Supplier;
use App\Models\Product;
use App\Models\User;
use App\Models\Category;
use App\Services\Resources\PurchaseInvoiceCalculatorService;
use App\Services\PurchaseService;
use Filament\Forms;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use \App\Filament\Traits\AdminAccess;

class ReturnPurchaseInvoiceResource extends Resource
{
    use AdminAccess;

    protected static ?string $model = ReturnPurchaseInvoice::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static ?string $navigationLabel = 'مرتجع المشتريات';

    protected static ?string $modelLabel = 'مرتجع شراء';

    protected static ?string $pluralModelLabel = 'مرتجع المشتريات';

    protected static string | \UnitEnum | null $navigationGroup = 'المشتريات';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('معلومات المرتجع')
                    ->schema([
                        Select::make('user_id')
                            ->label('المستخدم')
                            ->options(User::all()->pluck('name', 'id'))
                            ->searchable()
                            ->required()
                            ->default(auth()->id()),

                        Select::make('supplier_id')
                            ->label('المورد')
                            ->relationship('supplier', 'name')
                            ->searchable()
                            ->required()
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->label('اسم المورد')
                                    ->required(),
                                TextInput::make('phone')
                                    ->label('رقم الهاتف')
                                    ->tel(),
                                TextInput::make('address')
                                    ->label('العنوان'),
                            ]),

                        TextInput::make('total')
                            ->label('إجمالي المرتجع (ج.م)')
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
                    ->columns(3),

                Section::make('أصناف المرتجع')
                    ->extraAttributes([
                        "x-init" => PurchaseInvoiceCalculatorService::getJavaScriptCalculation(),
                    ])
                    ->schema([
                        Actions::make([
                            ProductImporterAction::make('importProducts')
                        ])
                            ->alignStart(),
                        ProductSelector::make()
                            ->columnSpanFull(),
                        Repeater::make('items')
                            ->label('الأصناف')
                            ->relationship('items', fn($query) => $query->with('product'))
                            ->table([
                                TableColumn::make('المنتج')
                                    ->width('200px'),
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
                                    ->formatStateUsing(function ($record) {
                                        if (!$record)
                                            return 'غير محدد';
                                        return $record->product_name != null ? $record->product_name : $record->product->name;
                                    })
                                    ->dehydrated(false)
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
                                    ->required()
                                    ->minValue(0)
                                    ->prefix('ج.م'),

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
                                return PurchaseInvoiceCalculatorService::prepareItemData($data);
                            })
                            ->mutateRelationshipDataBeforeSaveUsing(function ($data) {
                                return PurchaseInvoiceCalculatorService::prepareItemData($data);
                            })
                            ->collapsible(),
                    ]),
            ])->columns(1);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('معلومات المرتجع')
                    ->schema([
                        TextEntry::make('id')
                            ->label('رقم المرتجع'),

                        TextEntry::make('supplier.name')
                            ->label('المورد'),

                        TextEntry::make('user.name')
                            ->label('المستخدم'),

                        TextEntry::make('total')
                            ->label('إجمالي المرتجع')
                            ->money('EGP'),

                        TextEntry::make('closed_at')
                            ->label('الحالة')
                            ->formatStateUsing(function ($state) {
                                return $state ? 'مغلق' : 'مفتوح';
                            })
                            ->badge()
                            ->color(fn(?string $state): string => $state ? 'success' : 'warning'),

                        TextEntry::make('created_at')
                            ->label('تاريخ الإنشاء')
                            ->dateTime('d/m/Y H:i'),
                    ])
                    ->columns(3),

                Section::make('إحصائيات المرتجع')
                    ->schema([
                        TextEntry::make('items_count')
                            ->label('عدد الأصناف')
                            ->getStateUsing(fn($record) => $record->items->count()),

                        TextEntry::make('total_quantity')
                            ->label('إجمالي الكمية')
                            ->getStateUsing(fn($record) => $record->items->sum('quantity')),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('رقم المرتجع')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('supplier.name')
                    ->label('المورد')
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
                    ->label('إجمالي المرتجع')
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
                    ->color(fn(string $state): string => $state ? 'success' : 'warning')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('supplier_id')
                    ->label('المورد')
                    ->relationship('supplier', 'name'),

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
            ])
            ->recordActions([
                CloseReturnPurchaseInvoiceAction::table(),
                PrintReturnPurchaseInvoiceAction::table(),
                ViewAction::make(),

                EditAction::make()
                    ->visible(fn(ReturnPurchaseInvoice $record): bool => is_null($record->closed_at)),

                DeleteAction::make()
                    ->visible(fn(ReturnPurchaseInvoice $record): bool => is_null($record->closed_at)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getRelations(): array
    {
        return [
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReturnPurchaseInvoices::route('/'),
            'create' => CreateReturnPurchaseInvoice::route('/create'),
            'view' => ViewReturnPurchaseInvoice::route('/{record}'),
            'edit' => EditReturnPurchaseInvoice::route('/{record}/edit'),
        ];
    }
}
