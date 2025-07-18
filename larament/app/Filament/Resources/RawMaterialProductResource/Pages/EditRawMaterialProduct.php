<?php

namespace App\Filament\Resources\RawMaterialProductResource\Pages;

use App\Filament\Resources\RawMaterialProductResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRawMaterialProduct extends EditRecord
{
    protected static string $resource = RawMaterialProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
