<?php

namespace App\Filament\Resources\ConsumableProductResource\Pages;

use App\Filament\Resources\ConsumableProductResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditConsumableProduct extends EditRecord
{
    protected static string $resource = ConsumableProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
