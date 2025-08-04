<?php

namespace App\Filament\Resources\StocktakingResource\Pages;

use App\Filament\Resources\StocktakingResource;
use App\Services\Resources\StocktakingCalculatorService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditStocktaking extends EditRecord
{
    protected static string $resource = StocktakingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Calculate the total from items
        if (isset($data['items']) && is_array($data['items'])) {
            $data['total'] = StocktakingCalculatorService::calculateStocktakingTotal($data['items']);
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
