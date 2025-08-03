<?php

namespace App\Filament\Resources\PurchaseInvoiceResource\Pages;

use App\Filament\Actions\ClosePurchaseInvoiceAction;
use App\Filament\Resources\PurchaseInvoiceResource;
use App\Filament\Resources\PurchaseInvoiceResource\RelationManagers\ItemsRelationManager;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use App\Models\PurchaseInvoice;
use App\Services\PurchaseService;
use Filament\Notifications\Notification;

class ViewPurchaseInvoice extends ViewRecord
{
    protected static string $resource = PurchaseInvoiceResource::class;


    public function getRelationManagers(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }
}
