<?php

namespace App\Modules\Manufacturing\Filament\Resources\ManufacturingRecipes\Pages;

use App\Modules\Manufacturing\Filament\Resources\ManufacturingRecipes\ManufacturingRecipeResource;
use App\Models\ManufacturingRecipe;
use App\Modules\Manufacturing\Services\ManufacturingRecipeService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditManufacturingRecipe extends EditRecord
{
    protected static string $resource = ManufacturingRecipeResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var ManufacturingRecipe $recipe */
        $recipe = $this->record;
        $main = $recipe->mainOutput;
        if ($main) {
            $data['main_product_id'] = $main->product_id;
            $data['batch_quantity'] = (float) $main->expected_quantity;
        }

        $data['inputs'] = $recipe->inputs()
            ->get()
            ->map(fn ($input) => [
                'product_id' => $input->product_id,
                'quantity' => (float) $input->quantity,
            ])
            ->all();

        $data['extra_outputs'] = $recipe->outputs()
            ->where('is_main', false)
            ->get()
            ->map(fn ($output) => [
                'product_id' => $output->product_id,
                'expected_quantity' => (float) $output->expected_quantity,
                'cost_method' => $output->cost_method ?? 'percent',
                'cost_value' => (float) ($output->cost_value ?? 0),
            ])
            ->all();

        return $data;
    }

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

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $outputs = $this->buildOutputsData($data);

        /** @var ManufacturingRecipe $record */
        return app(ManufacturingRecipeService::class)->update($record, [
            'name' => (string) ($data['name'] ?? $record->name),
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $record->notes,
            'is_active' => (bool) ($data['is_active'] ?? $record->is_active),
            'inputs' => $data['inputs'] ?? [],
            'outputs' => $outputs,
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
