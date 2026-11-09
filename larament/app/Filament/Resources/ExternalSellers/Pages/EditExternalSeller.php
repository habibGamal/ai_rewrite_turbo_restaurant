<?php

namespace App\Filament\Resources\ExternalSellers\Pages;

use App\Filament\Resources\ExternalSellers\ExternalSellerResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditExternalSeller extends EditRecord
{
    protected static string $resource = ExternalSellerResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
