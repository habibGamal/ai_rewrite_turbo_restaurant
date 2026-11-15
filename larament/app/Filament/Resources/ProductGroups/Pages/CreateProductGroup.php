<?php

namespace App\Filament\Resources\ProductGroups\Pages;

use App\Filament\Resources\ProductGroups\ProductGroupResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductGroup extends CreateRecord
{
    protected static string $resource = ProductGroupResource::class;

    /** Variants are generated from the edit page, so land there after creating. */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
