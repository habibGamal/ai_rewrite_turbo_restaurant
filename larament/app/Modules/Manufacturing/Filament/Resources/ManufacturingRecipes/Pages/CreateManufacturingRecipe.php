<?php

namespace App\Modules\Manufacturing\Filament\Resources\ManufacturingRecipes\Pages;

use App\Modules\Manufacturing\Filament\Resources\ManufacturingRecipes\ManufacturingRecipeResource;
use App\Modules\Manufacturing\Services\ManufacturingRecipeService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateManufacturingRecipe extends CreateRecord
{
    protected static string $resource = ManufacturingRecipeResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    protected function buildOutputsData(array $data): array
    {
        if (isset($data['main_product_id'])) {
            $mainRow = [
                'product_id' => (int) $data['main_product_id'],
                'is_main' => true,
                'expected_quantity' => (float) ($data['batch_quantity'] ?? 1),
                'cost_method' => 'percent',
                'cost_value' => 0.0,
            ];

            $extraRows = array_map(fn ($row) => [
                'product_id' => (int) ($row['product_id'] ?? 0),
                'is_main' => false,
                'expected_quantity' => (float) ($row['expected_quantity'] ?? 0),
                'cost_method' => (string) ($row['cost_method'] ?? 'percent'),
                'cost_value' => (float) ($row['cost_value'] ?? 0.0),
            ], $data['extra_outputs'] ?? []);

            return array_merge([$mainRow], $extraRows);
        }

        return $data['outputs'] ?? [];
    }

    protected function afterValidate(): void
    {
        $outputs = $this->buildOutputsData($this->data);

        try {
            app(ManufacturingRecipeService::class)->validateData(
                $this->data['inputs'] ?? [],
                $outputs,
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

    protected function handleRecordCreation(array $data): Model
    {
        $outputs = $this->buildOutputsData($data);

        return app(ManufacturingRecipeService::class)->create([
            'name' => (string) $data['name'],
            'notes' => $data['notes'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'inputs' => $data['inputs'] ?? [],
            'outputs' => $outputs,
        ]);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
