<?php

namespace App\Filament\Resources\ManufacturingOrders\Pages;

use App\Filament\Actions\CompleteManufacturingOrderAction;
use App\Filament\Resources\ManufacturingOrders\ManufacturingOrderResource;
use App\Models\ManufacturingOrder;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditManufacturingOrder extends EditRecord
{
    protected static string $resource = ManufacturingOrderResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function beforeSave(): void
    {
        ManufacturingOrder::query()->lockForUpdate()->findOrFail($this->record->getKey())->assertDraft();
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(), CompleteManufacturingOrderAction::make(),
            DeleteAction::make()->visible(fn (ManufacturingOrder $record) => ManufacturingOrderResource::canDelete($record)),
        ];
    }
}
