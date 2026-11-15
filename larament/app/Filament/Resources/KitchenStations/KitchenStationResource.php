<?php

namespace App\Filament\Resources\KitchenStations;

use App\Filament\Resources\KitchenStations\Pages\CreateKitchenStation;
use App\Filament\Resources\KitchenStations\Pages\EditKitchenStation;
use App\Filament\Resources\KitchenStations\Pages\ListKitchenStations;
use App\Filament\Resources\KitchenStations\RelationManagers\CategoriesRelationManager;
use App\Filament\Resources\KitchenStations\RelationManagers\ProductsRelationManager;
use App\Filament\Traits\BelongsToModule;
use App\Models\KitchenStation;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class KitchenStationResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = KitchenStation::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-fire';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة المطعم';

    protected static ?int $navigationSort = 2;

    public static function getModelLabel(): string
    {
        return 'محطة مطبخ';
    }

    public static function getPluralModelLabel(): string
    {
        return 'محطات المطبخ';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('اسم المحطة')
                    ->required()
                    ->maxLength(255),

                Grid::make(2)
                    ->schema([
                        Toggle::make('is_active')
                            ->label('نشطة')
                            ->default(true)
                            ->inline(false),

                        TextInput::make('sort_order')
                            ->label('ترتيب العرض')
                            ->numeric()
                            ->minValue(0)
                            ->default(0),
                    ]),

                Placeholder::make('resolved_products_count')
                    ->label('عدد المنتجات المرتبطة')
                    ->content(fn (?KitchenStation $record): int => $record?->resolvedProductsCount() ?? 0)
                    ->hidden(fn (?KitchenStation $record): bool => $record === null),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query): Builder {
                /** @var KitchenStation $station */
                $station = $query->getModel();

                return $station->scopeWithResolvedProductsCount($query);
            })
            ->columns([
                TextColumn::make('name')
                    ->label('اسم المحطة')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('نشطة')
                    ->boolean(),
                TextColumn::make('sort_order')
                    ->label('ترتيب العرض')
                    ->sortable(),
                TextColumn::make('categories_count')
                    ->label('عدد الفئات')
                    ->counts('categories')
                    ->sortable(),
                TextColumn::make('resolved_products_count')
                    ->label('عدد المنتجات المرتبطة')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('تاريخ التحديث')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort_order', 'asc')
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            CategoriesRelationManager::class,
            ProductsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKitchenStations::route('/'),
            'create' => CreateKitchenStation::route('/create'),
            'edit' => EditKitchenStation::route('/{record}/edit'),
        ];
    }
}
