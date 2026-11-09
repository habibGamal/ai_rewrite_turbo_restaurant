<?php

namespace App\Filament\Resources\KitchenStations\RelationManagers;

use App\Enums\ProductType;
use App\Models\Category;
use App\Models\Product;
use Filament\Actions\AssociateAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DissociateAction;
use Filament\Actions\DissociateBulkAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'products';

    protected static ?string $inverseRelationship = 'kitchenStation';

    protected static ?string $title = 'منتجات مخصصة';

    protected static ?string $modelLabel = 'منتج مخصص';

    protected static ?string $pluralModelLabel = 'منتجات مخصصة';

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
        return $table
            ->recordTitleAttribute('name')
            ->inverseRelationship('kitchenStation')
            ->heading('منتجات مخصصة')
            ->description('منتجات تم تحديد محطتها بشكل مخصص على مستوى المنتج. ترث المنتجات عادة محطة المطبخ من تصنيفها.')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['category.kitchenStation']))
            ->columns([
                TextColumn::make('name')
                    ->label('اسم المنتج')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('category.name')
                    ->label('التصنيف')
                    ->placeholder('— بدون تصنيف')
                    ->sortable(),

                TextColumn::make('category.kitchenStation.name')
                    ->label('محطة التصنيف الأصلية')
                    ->placeholder('— بدون محطة (عام)')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('category_id')
                    ->label('التصنيف')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->headerActions([
                AssociateAction::make()
                    ->label('ربط منتجات')
                    ->multiple()
                    ->preloadRecordSelect()
                    ->recordSelect(fn (Select $select) => $select->searchable())
                    ->recordTitle(function (Model $record): string {
                        if (! $record instanceof Product) {
                            return '';
                        }

                        $category = $record->category;

                        return $category instanceof Category
                            ? "{$record->name} ({$category->name})"
                            : "{$record->name} — بدون تصنيف";
                    })
                    ->recordSelectOptionsQuery(fn (Builder $query) => $query
                        ->with('category')
                        ->where(function (Builder $q) {
                            $q->whereNull('type')
                                ->orWhere('type', '!=', ProductType::RawMaterial->value);
                        })
                        ->where(function (Builder $q) {
                            $q->where('legacy', false)
                                ->orWhereNull('legacy');
                        })
                    )
                    ->authorize(fn (): bool => auth()->user()?->can('update', $this->getOwnerRecord()) ?? false),
            ])
            ->recordActions([
                DissociateAction::make()
                    ->label('إلغاء التخصيص')
                    ->authorize(fn (): bool => auth()->user()?->can('update', $this->getOwnerRecord()) ?? false),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DissociateBulkAction::make()
                        ->label('إلغاء التخصيص')
                        ->authorize(fn (): bool => auth()->user()?->can('update', $this->getOwnerRecord()) ?? false),
                ]),
            ]);
    }
}
