<?php

namespace App\Filament\Actions\Forms;

use App\Filament\Components\Forms\ProductSelector;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductOption;
use App\Services\FeatureService;
use App\Services\Products\ProductGroupGridService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Purchase quantities for a whole product group at once: pick the group,
 * type quantities into a size x colour grid (other axes chosen by filters)
 * and one unit cost, and every non-empty cell becomes an invoice line.
 * Only shown while product variants are switched on.
 */
class GroupQuantityGridAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'groupQuantityGrid';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('إضافة مقاسات وألوان')
            ->icon('heroicon-m-table-cells')
            ->color('info')
            ->visible(fn (): bool => app(FeatureService::class)->variantsEnabled())
            ->modalHeading('إضافة كميات موديل')
            ->modalWidth('5xl')
            ->schema(fn (): array => [
                Grid::make(4)->schema([
                    Select::make('group_id')
                        ->label('الموديل')
                        ->options(fn (): array => ProductGroup::query()
                            ->where('is_active', true)
                            ->whereHas('variants')
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (?string $state, Set $set): void {
                            $group = ProductGroup::query()->find($state);
                            $grid = $group ? app(ProductGroupGridService::class)->grid($group) : null;
                            $set('row_option_id', $grid === null ? null : $grid['row']?->id);
                            $set('col_option_id', $grid === null ? null : $grid['col']?->id);
                            $set('fixed', []);
                            $set('qty', []);
                            $set('cost', $group ? (float) $group->base_cost : null);
                        }),
                    Select::make('row_option_id')
                        ->label('الصفوف')
                        ->options(fn (Get $get): array => self::axisOptions($get))
                        ->live(),
                    Select::make('col_option_id')
                        ->label('الأعمدة')
                        ->options(fn (Get $get): array => self::axisOptions($get))
                        ->live(),
                    TextInput::make('cost')
                        ->label('سعر الوحدة (ج.م)')
                        ->numeric()
                        ->minValue(0)
                        ->required(),
                ]),
                Grid::make(4)->schema(fn (Get $get): array => self::filterFields($get)),
                Grid::make(1)->schema(fn (Get $get): array => self::gridFields($get)),
            ])
            ->action(function (array $data, Set $set, Get $get): void {
                $quantities = collect($data['qty'] ?? [])
                    ->map(fn ($qty): float => (float) $qty)
                    ->filter(fn (float $qty): bool => $qty > 0);

                if ($quantities->isEmpty()) {
                    Notification::make()->warning()->title('لم يتم إدخال أي كمية')->send();

                    return;
                }

                $cost = (float) ($data['cost'] ?? 0);
                $products = Product::query()->whereKey($quantities->keys()->map(fn ($id): int => (int) $id)->all())->get()->keyBy('id');

                foreach ($quantities as $productId => $qty) {
                    $product = $products->get((int) $productId);
                    if ($product === null) {
                        continue;
                    }

                    ProductSelector::addScannedProduct($product, $qty, $set, $get);
                }

                // The grid's cost applies to every line of this group.
                $items = collect($get('items') ?? [])
                    ->map(function (array $item) use ($products, $cost): array {
                        if ($products->has((int) ($item['product_id'] ?? 0))) {
                            $item['price'] = $cost;
                            $item['total'] = $cost * (float) ($item['quantity'] ?? 0);
                        }

                        return $item;
                    })
                    ->all();
                $set('items', $items);
                $set('total', collect($items)->sum(fn (array $item): float => (float) ($item['total'] ?? 0)));

                Notification::make()
                    ->success()
                    ->title('تمت إضافة '.$quantities->count().' صنف')
                    ->send();
            });
    }

    /**
     * @return array<int, string>
     */
    private static function axisOptions(Get $get): array
    {
        $group = ProductGroup::query()->find($get('group_id'));

        return $group
            ? app(ProductGroupGridService::class)->axes($group)->pluck('name', 'id')->all()
            : [];
    }

    /**
     * @return array<int, Select>
     */
    private static function filterFields(Get $get): array
    {
        $group = ProductGroup::query()->find($get('group_id'));
        if ($group === null) {
            return [];
        }

        $service = app(ProductGroupGridService::class);
        $grid = $service->grid($group, self::intOrNull($get('row_option_id')), self::intOrNull($get('col_option_id')));

        return $service->otherAxes($group, $grid['row'], $grid['col'])
            ->map(fn (ProductOption $option): Select => Select::make('fixed.'.$option->id)
                ->label($option->name)
                ->options($option->values->pluck('value', 'id')->all())
                ->required()
                ->live())
            ->all();
    }

    /**
     * One numeric field per cell, named qty.<product id>; a row of fields per
     * value on the row axis, with the column values as labels.
     *
     * @return array<int, mixed>
     */
    private static function gridFields(Get $get): array
    {
        $group = ProductGroup::query()->find($get('group_id'));
        if ($group === null) {
            return [Text::make('اختر الموديل لعرض جدول الكميات.')];
        }

        $fixed = collect($get('fixed') ?? [])
            ->mapWithKeys(fn ($value, $option): array => [(int) $option => self::intOrNull($value)])
            ->all();
        $grid = app(ProductGroupGridService::class)->grid(
            $group,
            self::intOrNull($get('row_option_id')),
            self::intOrNull($get('col_option_id')),
            $fixed,
        );

        $columns = max(1, $grid['cols']->count());
        $rows = [];
        foreach ($grid['rows'] as $rowValue) {
            $cells = [];
            foreach ($grid['cols']->isEmpty() ? [null] : $grid['cols'] as $colValue) {
                $product = $grid['cells'][$rowValue->id][$colValue === null ? 0 : $colValue->id] ?? null;
                $label = $rowValue->value.($colValue ? ' / '.$colValue->value : '');

                $cells[] = $product === null
                    ? Text::make($label.': —')
                    : TextInput::make('qty.'.$product->id)
                        ->label($label)
                        ->numeric()
                        ->minValue(0)
                        ->placeholder('0');
            }

            $rows[] = Grid::make($columns)->schema($cells);
        }

        return $rows;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
