<?php

namespace App\Filament\Resources\ReturnPurchaseInvoices\Pages;

use App\Filament\Actions\PrintLabelsAction;
use App\Filament\Resources\ReturnPurchaseInvoices\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\ReturnPurchaseInvoices\ReturnPurchaseInvoiceResource;
use Filament\Resources\Pages\ViewRecord;

class ViewReturnPurchaseInvoice extends ViewRecord
{
    protected static string $resource = ReturnPurchaseInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PrintLabelsAction::document(),
        ];
    }

    public function getRelationManagers(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }
}
