<?php

namespace App\Filament\Resources;

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
use Awcodes\TableRepeater\Components\TableRepeater;
use Awcodes\TableRepeater\Header;
use Filament\Forms;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Actions;
use Filament\Infolists\Infolist;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\Section as InfoSection;
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

    protected static ?string $navigationIcon = 'heroicon-o-trash';

    protected static ?string $navigationLabel = 'التالف';

    protected static ?string $modelLabel = 'سجل تالف';

    protected static ?string $pluralModelLabel = 'سجلات التالف';

    protected static ?string $navigationGroup = 'إدارة المخزون';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
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
                        TableRepeater::make('items')
                            ->label('الأصناف')
                            ->relationship('items', fn($query) => $query->with('product.inventoryItem'))
                            ->headers([
                                Header::make('product_id')->label('المنتج')->width('200px'),
                                Header::make('stock_quantity')->label('الكمية الحالية')->width('100px'),
                                Header::make('quantity')->label('الكمية')->width('100px'),
                                Header::make('price')->label('سعر الوحدة (ج.م)')->width('120px'),
                                Header::make('total')->label('الإجمالي (ج.م)')->width('120px'),
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
                                    ->label('الكمية')
                                    ->formatStateUsing(function ($record) {
                                        return ($record->product?->inventoryItem->quantity ?? 'غير محدد');
                                    })
                                    ->disabled(),

                                TextInput::make('quantity')
                                    ->label('الكمية')
                                    ->numeric()
                                    ->required()
                                    ->default(1)
                                    ->minValue(1),


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
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                InfoSection::make('معلومات سجل التالف')
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
                Tables\Filters\SelectFilter::make('user_id')
                    ->label('المستخدم')
                    ->relationship('user', 'name'),

                Tables\Filters\Filter::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->form([
                        Forms\Components\DatePicker::make('created_from')
                            ->label('من تاريخ'),
                        Forms\Components\DatePicker::make('created_until')
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

                Tables\Filters\TernaryFilter::make('closed_at')
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
            ->actions([
                CloseWasteAction::table(),
                PrintWasteAction::table(),
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
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
            'index' => Pages\ListWastes::route('/'),
            'create' => Pages\CreateWaste::route('/create'),
            'view' => Pages\ViewWaste::route('/{record}'),
            'edit' => Pages\EditWaste::route('/{record}/edit'),
        ];
    }
}
