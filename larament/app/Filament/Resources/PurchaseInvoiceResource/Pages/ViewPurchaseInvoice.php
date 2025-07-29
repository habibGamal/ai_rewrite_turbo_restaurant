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

    protected function getHeaderActions(): array
    {
        return [
            ClosePurchaseInvoiceAction::make(),

            Actions\EditAction::make()
                ->visible(fn(PurchaseInvoice $record): bool => is_null($record->closed_at)),
        ];
    }

    public function getRelationManagers(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }
}
