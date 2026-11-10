<?php

namespace App\Filament\Resources\StockTransfers\Pages;

use App\Filament\Actions\ConfirmStockTransferAction;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditStockTransfer extends EditRecord
{
    protected static string $resource = StockTransferResource::class;

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
