<?php

namespace App\Modules\Manufacturing\Filament\Resources\ManufacturingOrders\Pages;

use App\Modules\Manufacturing\Filament\Actions\CompleteManufacturingOrderAction;
use App\Modules\Manufacturing\Filament\Resources\ManufacturingOrders\ManufacturingOrderResource;
use App\Models\ManufacturingOrder;
use App\Models\Product;
use App\Modules\Manufacturing\Services\ManufacturingOrderService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditManufacturingOrder extends EditRecord
{
    protected static string $resource = ManufacturingOrderResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function beforeSave(): void
    {
        ManufacturingOrder::query()->lockForUpdate()->findOrFail($this->record->getKey())->assertDraft();
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var ManufacturingOrder $record */
        return app(ManufacturingOrderService::class)->saveDraftLines(
            $record,
            $data['inputs'] ?? [],
            $data['outputs'] ?? [],
            isset($data['planned_main_quantity']) ? (float) $data['planned_main_quantity'] : null,
            $data['notes'] ?? null,
        );
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var ManufacturingOrder $order */
        $order = $this->record->load(['inputs.product', 'outputs.product', 'recipe.mainOutput']);

        $data['inputs'] = $order->inputs->map(fn ($input) => [
            'id' => $input->id,
            'product_id' => $input->product_id,
            'unit' => Product::formatUnit($input->product?->unit),
            'planned_quantity' => (float) $input->planned_quantity,
            'actual_quantity' => (float) $input->actual_quantity,
            'unit_cost' => (float) ($input->product->cost ?? 0),
            'line_cost' => round((float) $input->actual_quantity * (float) ($input->product->cost ?? 0), 2),
        ])->all();

        $data['outputs'] = $order->outputs->map(fn ($output) => [
            'id' => $output->id,
            'product_id' => $output->product_id,
            'unit' => Product::formatUnit($output->product?->unit),
            'is_main' => (bool) $output->is_main,
            'expected_quantity' => (float) $output->expected_quantity,
            'actual_quantity' => $output->actual_quantity !== null ? (float) $output->actual_quantity : (float) $output->expected_quantity,
            'cost_method' => $output->cost_method ?? 'percent',
            'cost_value' => (float) ($output->cost_value ?? 0),
            'variance_quantity' => round((float) ($output->actual_quantity ?? $output->expected_quantity) - (float) $output->expected_quantity, 3),
        ])->all();

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            CompleteManufacturingOrderAction::make(),
            DeleteAction::make()->visible(fn (ManufacturingOrder $record) => ManufacturingOrderResource::canDelete($record)),
        ];
    }
}
