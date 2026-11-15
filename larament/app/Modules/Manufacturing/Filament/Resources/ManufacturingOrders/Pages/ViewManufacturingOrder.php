<?php

namespace App\Modules\Manufacturing\Filament\Resources\ManufacturingOrders\Pages;

use App\Modules\Manufacturing\Filament\Actions\CompleteManufacturingOrderAction;
use App\Modules\Manufacturing\Filament\Resources\ManufacturingOrders\ManufacturingOrderResource;
use App\Models\ManufacturingOrder;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewManufacturingOrder extends ViewRecord
{
    protected static string $resource = ManufacturingOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->visible(fn (ManufacturingOrder $record) => ManufacturingOrderResource::canEdit($record)),
            CompleteManufacturingOrderAction::make(),
        ];
    }
}
