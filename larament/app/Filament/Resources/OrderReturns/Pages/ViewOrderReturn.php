<?php

namespace App\Filament\Resources\OrderReturns\Pages;

use App\Filament\Resources\OrderReturns\OrderReturnResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewOrderReturn extends ViewRecord
{
    protected static string $resource = OrderReturnResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // No actions for view-only resource
        ];
    }
}
