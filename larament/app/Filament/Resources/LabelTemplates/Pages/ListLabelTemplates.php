<?php

namespace App\Filament\Resources\LabelTemplates\Pages;

use App\Filament\Resources\LabelTemplates\LabelTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLabelTemplates extends ListRecords
{
    protected static string $resource = LabelTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
