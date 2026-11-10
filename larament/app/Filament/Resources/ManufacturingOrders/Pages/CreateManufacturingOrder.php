<?php

namespace App\Filament\Resources\ManufacturingOrders\Pages;

use App\Filament\Resources\ManufacturingOrders\ManufacturingOrderResource;
use App\Models\ManufacturingRecipe;
use App\Services\Manufacturing\ManufacturingOrderService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateManufacturingOrder extends CreateRecord
{
    protected static string $resource = ManufacturingOrderResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function handleRecordCreation(array $data): Model
    {
        $order = app(ManufacturingOrderService::class)->createFromRecipe(
            ManufacturingRecipe::findOrFail($data['manufacturing_recipe_id']),
            (int) $data['source_warehouse_id'],
            (int) $data['destination_warehouse_id'],
            (float) $data['planned_main_quantity'],
        );
        $order->update(['notes' => $data['notes'] ?? null]);

        return $order;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
