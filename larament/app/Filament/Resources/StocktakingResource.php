<?php

namespace App\Filament\Resources;

use App\Filament\Actions\Forms\StocktakingProductImporterAction;
use App\Filament\Actions\CloseStocktakingAction;
use App\Filament\Resources\StocktakingResource\Pages;
use App\Filament\Resources\StocktakingResource\RelationManagers;
use App\Models\Stocktaking;
use App\Models\Product;
use App\Models\User;
use App\Models\InventoryItem;
use App\Services\Resources\StocktakingCalculatorService;
use App\Enums\ProductType;
use Awcodes\TableRepeater\Components\TableRepeater;
use Awcodes\TableRepeater\Header;
use Filament\Forms;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Form;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Actions;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists;
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

class StocktakingResource extends Resource
{
    use AdminAccess;

    protected static ?string $model = Stocktaking::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'الجرد';

    protected static ?string $modelLabel = 'جرد';

    protected static ?string $pluralModelLabel = 'الجرد';

    protected static ?string $navigationGroup = 'المخزون';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
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

                        Forms\Components\TextArea::make('notes')
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

                        TableRepeater::make('items')
                            ->label('الأصناف')
                            ->relationship('items', fn($query) => $query->with('product'))
                            ->headers([
                                Header::make('product_id')->label('المنتج')->width('150px'),
                                // Header::make('stock_quantity')->label('الكمية المخزنة')->width('120px'),
                                Header::make('real_quantity')->label('الكمية الفعلية')->width('120px'),
                                Header::make('price')->label('سعر الوحدة (ج.م)')->width('120px'),
                                Header::make('total')->label('الفرق (ج.م)')->width('120px'),
                            ])
                            ->schema([
                                Hidden::make('product_id'),

                                TextInput::make('product_name')
                                    ->label('المنتج')
                                    ->formatStateUsing(function ($record,$state) {
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
                            ->columns(5)
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
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                InfoSection::make('معلومات الجرد')
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
                        true: fn (Builder $query) => $query->whereNotNull('closed_at'),
                        false: fn (Builder $query) => $query->whereNull('closed_at'),
                        blank: fn (Builder $query) => $query,
                    ),
            ])
            ->actions([
                CloseStocktakingAction::table(),
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
            'index' => Pages\ListStocktakings::route('/'),
            'create' => Pages\CreateStocktaking::route('/create'),
            'view' => Pages\ViewStocktaking::route('/{record}'),
            'edit' => Pages\EditStocktaking::route('/{record}/edit'),
        ];
    }
}
