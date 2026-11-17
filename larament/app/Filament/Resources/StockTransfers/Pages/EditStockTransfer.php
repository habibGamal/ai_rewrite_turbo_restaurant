<?php

namespace App\Filament\Resources\StockTransfers\Pages;

use App\Filament\Actions\ConfirmStockTransferAction;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use App\Services\Resources\StockTransferCalculatorService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditStockTransfer extends EditRecord
{
    protected static string $resource = StockTransferResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (isset($data['items']) && is_array($data['items'])) {
            $total = StockTransferCalculatorService::calculateTotal($data['items']);
            $data['total_cost'] = $total;
            $data['total'] = $total;
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            ConfirmStockTransferAction::make(),
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
