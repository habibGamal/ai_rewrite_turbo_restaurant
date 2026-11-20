<?php

namespace App\Modules\Manufacturing\Filament\Resources\RawMaterialProducts\Pages;

use App\Modules\Manufacturing\Filament\Resources\RawMaterialProducts\RawMaterialProductResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRawMaterialProduct extends CreateRecord
{
    protected static string $resource = RawMaterialProductResource::class;
}
