<?php

namespace App\Modules\ExternalSellers\Filament\Resources\ExternalSellers\Pages;

use App\Modules\ExternalSellers\Filament\Resources\ExternalSellers\ExternalSellerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateExternalSeller extends CreateRecord
{
    protected static string $resource = ExternalSellerResource::class;
}
