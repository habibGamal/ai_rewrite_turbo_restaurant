<?php

namespace App\Filament\Resources\OrderTypeRows\Pages;

use App\Filament\Resources\OrderTypeRows\OrderTypeRowResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOrderTypeRow extends CreateRecord
{
    protected static string $resource = OrderTypeRowResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['is_system'] = false;

        return $data;
    }
}
