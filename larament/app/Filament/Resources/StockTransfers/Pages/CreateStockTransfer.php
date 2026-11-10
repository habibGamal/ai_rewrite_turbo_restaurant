<?php

namespace App\Filament\Resources\StockTransfers\Pages;

use App\Filament\Resources\StockTransfers\StockTransferResource;
use Filament\Resources\Pages\CreateRecord;

class CreateStockTransfer extends CreateRecord
{
    protected static string $resource = StockTransferResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
