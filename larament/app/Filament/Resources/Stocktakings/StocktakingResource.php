<?php

namespace App\Filament\Resources\Stocktakings;

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
use App\Filament\Resources\Stocktakings\Pages\ListStocktakings;
use App\Filament\Resources\Stocktakings\Pages\CreateStocktaking;
use App\Filament\Resources\Stocktakings\Pages\ViewStocktaking;
use App\Filament\Resources\Stocktakings\Pages\EditStocktaking;
use App\Filament\Actions\Forms\StocktakingProductImporterAction;
use App\Filament\Actions\CloseStocktakingAction;
use App\Filament\Actions\PrintStocktakingAction;
use App\Filament\Components\Forms\StocktakingProductSelector;
use App\Filament\Resources\StocktakingResource\Pages;
use App\Filament\Resources\StocktakingResource\RelationManagers;
use App\Models\Stocktaking;
use App\Models\Product;
use App\Models\User;
use App\Models\InventoryItem;
use App\Services\Resources\StocktakingCalculatorService;
use App\Enums\ProductType;
use Filament\Forms;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists;
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

class StocktakingResource extends Resource
{
    use AdminAccess;

    protected static ?string $model = Stocktaking::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'الجرد';

    protected static ?string $modelLabel = 'جرد';

    protected static ?string $pluralModelLabel = 'الجرد';

    protected static string | \UnitEnum | null $navigationGroup = 'إدارة المخزون';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('معلومات الجرد')
                    ->schema([
                        Select::make('user_id')
                            ->label('المستخدم')
                            ->options(User::all()->pluck('name', 'id'))
                            ->searchable()
                            ->required()
                            ->default(auth()->id()),

                        TextInput::make('total')
                            ->label('إجمالي الفرق (ج.م)')
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

                Section::make('أصناف الجرد')
                    ->extraAttributes([
                        "x-init" => StocktakingCalculatorService::getJavaScriptCalculation(),
                    ])
                    ->schema([
                        Actions::make([
                            StocktakingProductImporterAction::make('importProducts'),
                        ])
                            ->alignStart(),
                        StocktakingProductSelector::make()
                            ->columnSpanFull(),
                        Repeater::make('items')
                            ->label('الأصناف')
                            ->relationship('items', fn($query) => $query->with('product'))
                            ->table([
                                TableColumn::make('المنتج')
                                    ->width('150px'),
                                TableColumn::make('الكمية الفعلية')
                                    ->width('120px'),
                                TableColumn::make('سعر الوحدة (ج.م)')
                                    ->width('120px'),
                                TableColumn::make('الفرق (ج.م)')
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

                                Hidden::make('stock_quantity')
                                    ->label('الكمية المخزنة')
                                    ->required()
                                    ->default(0)
                                    ->dehydrated(true),

                                TextInput::make('real_quantity')
                                    ->label('الكمية الفعلية')
                                    ->numeric()
                                    ->required()
                                    ->default(0)
                                    ->minValue(0),

                                TextInput::make('price')
                                    ->label('سعر الوحدة (ج.م)')
                                    ->numeric()
                                    ->required()
                                    ->minValue(0)
                                    ->prefix('ج.م')
                                    ->disabled()
                                    ->dehydrated(true),

                                TextInput::make('total')
                                    ->label('الفرق (ج.م)')
                                    ->numeric()
                                    ->prefix('ج.م')
                                    ->disabled()
                                    ->dehydrated(false),
                            ])
                            ->defaultItems(0)
                            ->reorderableWithButtons()
                            ->dehydrated(true)
                            ->mutateRelationshipDataBeforeCreateUsing(function ($data) {
                                return StocktakingCalculatorService::prepareItemData($data);
                            })
                            ->mutateRelationshipDataBeforeSaveUsing(function ($data) {
                                return StocktakingCalculatorService::prepareItemData($data);
                            })
                            ->collapsible(),
                    ]),
            ])->columns(1);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('معلومات الجرد')
                    ->schema([
                        TextEntry::make('id')
                            ->label('رقم الجرد'),

                        TextEntry::make('user.name')
                            ->label('المستخدم'),

                        TextEntry::make('total')
                            ->label('إجمالي الفرق')
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
                    ->label('رقم الجرد')
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
                    ->label('إجمالي الفرق')
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
                CloseStocktakingAction::table(),
                PrintStocktakingAction::table(),
                ViewAction::make(),

                EditAction::make()
                    ->visible(fn(Stocktaking $record): bool => is_null($record->closed_at)),

                DeleteAction::make()
                    ->visible(fn(Stocktaking $record): bool => is_null($record->closed_at)),
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
            'index' => ListStocktakings::route('/'),
            'create' => CreateStocktaking::route('/create'),
            'view' => ViewStocktaking::route('/{record}'),
            'edit' => EditStocktaking::route('/{record}/edit'),
        ];
    }
}
