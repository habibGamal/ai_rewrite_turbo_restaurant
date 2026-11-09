<?php

namespace App\Filament\Resources\KitchenStations\RelationManagers;

use App\Models\Category;
use App\Models\KitchenStation;
use Filament\Actions\AssociateAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DissociateAction;
use Filament\Actions\DissociateBulkAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CategoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'categories';

    protected static ?string $inverseRelationship = 'kitchenStation';

    protected static ?string $title = 'التصنيفات';

    protected static ?string $modelLabel = 'تصنيف';

    protected static ?string $pluralModelLabel = 'التصنيفات';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public function isReadOnly(): bool
    {
        return ! (auth()->user()?->can('update', $this->getOwnerRecord()) ?? false);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        /** @var KitchenStation $station */
        $station = $this->getOwnerRecord();

        return $table
            ->recordTitleAttribute('name')
            ->inverseRelationship('kitchenStation')
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount([
                'products',
                'products as overriding_products_count' => fn (Builder $q) => $q
                    ->whereNotNull('kitchen_station_id')
                    ->where('kitchen_station_id', '!=', $station->getKey()),
            ]))
            ->columns([
                TextColumn::make('name')
                    ->label('الاسم')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('products_count')
                    ->label('عدد المنتجات')
                    ->sortable(),

                TextColumn::make('overriding_products_count')
                    ->label('منتجات بمحطة مختلفة')
                    ->sortable()
                    ->badge()
                    ->color(fn ($state): string => (int) $state > 0 ? 'warning' : 'gray'),
            ])
            ->headerActions([
                AssociateAction::make()
                    ->label('ربط تصنيفات')
                    ->multiple()
                    ->preloadRecordSelect()
                    ->recordSelect(fn (Select $select) => $select->searchable())
                    ->recordTitle(function (Model $record): string {
                        if (! $record instanceof Category) {
                            return '';
                        }

                        $currentStation = $record->kitchenStation;

                        return $currentStation instanceof KitchenStation
                            ? "{$record->name} — حالياً: {$currentStation->name}"
                            : "{$record->name} — بدون محطة";
                    })
                    ->recordSelectOptionsQuery(fn (Builder $query) => $query->with('kitchenStation'))
                    ->authorize(fn (): bool => auth()->user()?->can('update', $this->getOwnerRecord()) ?? false),
            ])
            ->recordActions([
                DissociateAction::make()
                    ->label('إلغاء الربط')
                    ->authorize(fn (): bool => auth()->user()?->can('update', $this->getOwnerRecord()) ?? false),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DissociateBulkAction::make()
                        ->label('إلغاء الربط')
                        ->authorize(fn (): bool => auth()->user()?->can('update', $this->getOwnerRecord()) ?? false),
                ]),
            ]);
    }
}
