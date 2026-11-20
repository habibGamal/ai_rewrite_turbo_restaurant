<?php

namespace App\Filament\Resources\PurchaseInvoices\Pages;

use App\Filament\Actions\ClosePurchaseInvoiceAction;
use App\Filament\Resources\PurchaseInvoices\PurchaseInvoiceResource;
use App\Services\Resources\PurchaseInvoiceCalculatorService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPurchaseInvoice extends EditRecord
{
    protected static string $resource = PurchaseInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ClosePurchaseInvoiceAction::make(),
            PurchaseInvoiceResource::printLabelsAction(),
            DeleteAction::make(),
        ];
    }

    /**
     * The items repeater is saved after the record, so the total is worked
     * out once the edited lines are in the database.
     */
    protected function afterSave(): void
    {
        $record = $this->getRecord();
        $record->update([
            'total' => PurchaseInvoiceCalculatorService::calculateInvoiceTotal($record->items()->get()),
        ]);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
