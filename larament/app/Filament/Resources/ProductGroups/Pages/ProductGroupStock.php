<?php

namespace App\Filament\Resources\ProductGroups\Pages;

use App\Filament\Resources\ProductGroups\ProductGroupResource;
use App\Models\ProductGroup;
use App\Models\ProductOption;
use App\Models\Warehouse;
use App\Services\Products\ProductGroupGridService;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

/**
 * Stock of a group's variants as a size x colour matrix, for one warehouse
 * or all of them. Any two axes can be put on the grid; the others are
 * picked from filters.
 *
 * @property ProductGroup $record
 */
class ProductGroupStock extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ProductGroupResource::class;

    protected string $view = 'filament.resources.product-groups.stock';

    public ?int $warehouseId = null;

    public ?int $rowOptionId = null;

    public ?int $colOptionId = null;

    /** @var array<int|string, int|string|null> option id => value id */
    public array $fixed = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(static::getResource()::canEdit($this->record), 403);

        $grid = app(ProductGroupGridService::class)->grid($this->record);
        $this->rowOptionId = $grid['row']?->id;
        $this->colOptionId = $grid['col']?->id;
    }

    public function getTitle(): string
    {
        return 'مخزون '.$this->record->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('edit')
                ->label('تعديل الموديل')
                ->url(EditProductGroup::getUrl(['record' => $this->record])),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function warehouses(): array
    {
        return Warehouse::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function matrix(): array
    {
        $service = app(ProductGroupGridService::class);
        $fixed = collect($this->fixed)
            ->mapWithKeys(fn ($value, $option): array => [(int) $option => $value === null || $value === '' ? null : (int) $value])
            ->all();

        $grid = $service->grid($this->record, $this->rowOptionId, $this->colOptionId, $fixed);
        $stock = $service->stock($this->record, $this->warehouseId ?: null);

        $quantities = [];
        $rowTotals = [];
        $colTotals = [];
        foreach ($grid['cells'] as $rowId => $cols) {
            foreach ($cols as $colId => $product) {
                $qty = $product !== null ? ($stock[$product->id] ?? 0.0) : null;
                $quantities[$rowId][$colId] = $qty;
                $rowTotals[$rowId] = ($rowTotals[$rowId] ?? 0) + ($qty ?? 0);
                $colTotals[$colId] = ($colTotals[$colId] ?? 0) + ($qty ?? 0);
            }
        }

        return [
            'axes' => $service->axes($this->record),
            'others' => $service->otherAxes($this->record, $grid['row'], $grid['col']),
            'row' => $grid['row'],
            'col' => $grid['col'],
            'rows' => $grid['rows'],
            'cols' => $grid['cols'],
            'quantities' => $quantities,
            'rowTotals' => $rowTotals,
            'colTotals' => $colTotals,
            'total' => array_sum($rowTotals),
        ];
    }

    public function axisName(?ProductOption $option): string
    {
        return $option?->name ?? '';
    }
}
