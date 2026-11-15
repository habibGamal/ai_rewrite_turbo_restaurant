<?php

namespace App\Modules\Manufacturing\Filament\Resources\ManufacturingOrders\Pages;

use App\Modules\Manufacturing\Filament\Resources\ManufacturingOrders\ManufacturingOrderResource;
use App\Models\ManufacturingRecipe;
use App\Modules\Manufacturing\Services\ManufacturingOrderService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateManufacturingOrder extends CreateRecord
{
    protected static string $resource = ManufacturingOrderResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function handleRecordCreation(array $data): Model
    {
        $recipe = ManufacturingRecipe::findOrFail($data['manufacturing_recipe_id']);
        $plannedQty = (float) $data['planned_main_quantity'];

        $inputs = ! empty($data['inputs']) ? $data['inputs'] : [];
        $outputs = ! empty($data['outputs']) ? $data['outputs'] : [];

        if (empty($inputs)) {
            $batchQty = (float) $recipe->mainOutput->expected_quantity;
            $scale = $batchQty > 0 ? $plannedQty / $batchQty : 1.0;
            foreach ($recipe->inputs as $input) {
                $qty = round((float) $input->quantity * $scale, 3);
                $inputs[] = [
                    'product_id' => $input->product_id,
                    'planned_quantity' => $qty,
                    'actual_quantity' => $qty,
                ];
            }
        }

        if (empty($outputs)) {
            $batchQty = (float) $recipe->mainOutput->expected_quantity;
            $scale = $batchQty > 0 ? $plannedQty / $batchQty : 1.0;
            foreach ($recipe->outputs as $output) {
                $qty = round((float) $output->expected_quantity * $scale, 3);
                $outputs[] = [
                    'product_id' => $output->product_id,
                    'is_main' => (bool) $output->is_main,
                    'expected_quantity' => $qty,
                    'actual_quantity' => $qty,
                    'cost_method' => $output->cost_method ?? 'percent',
                    'cost_value' => (float) ($output->cost_value ?? 0),
                ];
            }
        }

        return app(ManufacturingOrderService::class)->createOrderWithLines(
            $recipe,
            (int) $data['source_warehouse_id'],
            (int) $data['destination_warehouse_id'],
            $plannedQty,
            $inputs,
            $outputs,
            $data['notes'] ?? null,
        );
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
