<?php

namespace App\Filament\Resources\ManufacturingRecipes\Pages;

use App\Filament\Resources\ManufacturingRecipes\ManufacturingRecipeResource;
use App\Services\Manufacturing\ManufacturingRecipeService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditManufacturingRecipe extends EditRecord
{
    protected static string $resource = ManufacturingRecipeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function afterValidate(): void
    {
        try {
            app(ManufacturingRecipeService::class)->validateData(
                $this->data['inputs'] ?? [],
                $this->data['outputs'] ?? [],
            );
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $key => $messages) {
                $errors[$key] = $messages;
                if (! str_starts_with($key, 'data.')) {
                    $errors['data.'.$key] = $messages;
                }
            }

            throw ValidationException::withMessages($errors);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
