<?php

namespace App\Modules\ExternalSellers\Filament\Resources\ExternalSellers\Pages;

use App\Modules\ExternalSellers\Filament\Resources\ExternalSellers\ExternalSellerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListExternalSellers extends ListRecords
{
    protected static string $resource = ExternalSellerResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
