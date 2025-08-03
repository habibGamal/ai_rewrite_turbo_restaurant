<?php

namespace App\Filament\Resources\ReturnPurchaseInvoiceResource\Pages;

use App\Filament\Actions\CloseReturnPurchaseInvoiceAction;
use App\Filament\Resources\ReturnPurchaseInvoiceResource;
use App\Filament\Resources\ReturnPurchaseInvoiceResource\RelationManagers\ItemsRelationManager;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use App\Models\ReturnPurchaseInvoice;
use App\Services\PurchaseService;
use Filament\Notifications\Notification;

class ViewReturnPurchaseInvoice extends ViewRecord
{
    protected static string $resource = ReturnPurchaseInvoiceResource::class;

    public function getRelationManagers(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }
}
