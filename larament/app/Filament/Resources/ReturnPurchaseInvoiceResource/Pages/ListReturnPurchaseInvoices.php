<?php

namespace App\Filament\Resources\ReturnPurchaseInvoiceResource\Pages;

use App\Filament\Resources\ReturnPurchaseInvoiceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListReturnPurchaseInvoices extends ListRecords
{
    protected static string $resource = ReturnPurchaseInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
