<?php

namespace App\Filament\Resources\OrderReturns\Pages;

use Filament\Actions\CreateAction;
use App\Filament\Resources\OrderReturns\OrderReturnResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListOrderReturns extends ListRecords
{
    protected static string $resource = OrderReturnResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('إرجاع طلب'),
        ];
    }
}
