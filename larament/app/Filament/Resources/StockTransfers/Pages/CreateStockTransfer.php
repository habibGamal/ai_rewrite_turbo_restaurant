<?php

namespace App\Filament\Resources\StockTransfers\Pages;

use App\Filament\Resources\StockTransfers\StockTransferResource;
use App\Services\Resources\StockTransferCalculatorService;
use Filament\Resources\Pages\CreateRecord;

class CreateStockTransfer extends CreateRecord
{
    protected static string $resource = StockTransferResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (isset($data['items']) && is_array($data['items'])) {
            $total = StockTransferCalculatorService::calculateTotal($data['items']);
            $data['total_cost'] = $total;
            $data['total'] = $total;
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
