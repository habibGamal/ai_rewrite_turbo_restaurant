<?php

namespace App\Services\Manufacturing;

use App\Enums\ProductType;
use App\Models\ManufacturingRecipe;
use App\Models\Product;
use BackedEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManufacturingRecipeService
{
    /**
     * Validate raw input and output arrays for a manufacturing recipe.
     *
     * @param  array<int|string, array<string, mixed>>  $inputs
     * @param  array<int|string, array<string, mixed>>  $outputs
     */
    public function validateData(array $inputs, array $outputs): void
    {
        $inputs = array_values($inputs);
        $outputs = array_values($outputs);

        if (count($inputs) === 0) {
            throw ValidationException::withMessages([
                'inputs' => 'يجب إضافة مدخل واحد على الأقل للوصفة',
            ]);
        }

        if (count($outputs) === 0) {
            throw ValidationException::withMessages([
                'outputs' => 'يجب إضافة مخرج رئيسي واحد على الأقل للوصفة',
            ]);
        }

        $mainCount = 0;
        $percentTotal = 0.0;
        $inputProductIds = [];
        $outputProductIds = [];

        foreach ($inputs as $input) {
            $productId = isset($input['product_id']) ? (int) $input['product_id'] : 0;
            $quantity = isset($input['quantity']) ? (float) $input['quantity'] : 0.0;

            if ($productId <= 0) {
                throw ValidationException::withMessages([
                    'inputs' => 'يجب اختيار منتج صالح لكل مدخل',
                ]);
            }

            if ($quantity <= 0) {
                throw ValidationException::withMessages([
                    'inputs' => 'يجب أن تكون كمية المدخل أكبر من صفر',
                ]);
            }

            $inputProductIds[] = $productId;
        }

        foreach ($outputs as $output) {
            $productId = isset($output['product_id']) ? (int) $output['product_id'] : 0;
            $isMain = (bool) ($output['is_main'] ?? false);
            $expectedQuantity = isset($output['expected_quantity']) ? (float) $output['expected_quantity'] : 0.0;
            $rawMethod = $output['cost_method'] ?? 'percent';
            $costMethod = $rawMethod instanceof BackedEnum ? (string) $rawMethod->value : (string) $rawMethod;
            $costValue = isset($output['cost_value']) ? (float) $output['cost_value'] : 0.0;

            if ($productId <= 0) {
                throw ValidationException::withMessages([
                    'outputs' => 'يجب اختيار منتج صالح لكل مخرج',
                ]);
            }

            if ($expectedQuantity <= 0) {
                throw ValidationException::withMessages([
                    'outputs' => 'يجب أن تكون الكمية المتوقعة للمخرج أكبر من صفر',
                ]);
            }

            if ($isMain) {
                $mainCount++;
            } else {
                if (! in_array($costMethod, ['percent', 'fixed'], true)) {
                    throw ValidationException::withMessages([
                        'outputs' => 'طريقة حساب التكلفة للمخرج غير صالحة',
                    ]);
                }

                if ($costValue < 0) {
                    throw ValidationException::withMessages([
                        'outputs' => 'لا يمكن أن تكون قيمة تكلفة المخرج سالبة',
                    ]);
                }

                if ($costMethod === 'percent') {
                    $percentTotal += $costValue;
                }
            }

            $outputProductIds[] = $productId;
        }

        if ($mainCount !== 1) {
            throw ValidationException::withMessages([
                'outputs' => 'يجب أن تحتوي الوصفة على مخرج رئيسي واحد بالضبط',
            ]);
        }

        if ($percentTotal >= 100.0) {
            throw ValidationException::withMessages([
                'outputs' => 'يجب أن يكون مجموع نسب المخرجات الفرعية أقل من 100%',
            ]);
        }

        $overlap = array_intersect($inputProductIds, $outputProductIds);
        if (! empty($overlap)) {
            throw ValidationException::withMessages([
                'outputs' => 'لا يمكن أن يكون المنتج مدخلاً ومخرجاً في نفس الوصفة',
            ]);
        }

        $allProductIds = array_values(array_unique(array_merge($inputProductIds, $outputProductIds)));
        $products = Product::query()
            ->whereIn('id', $allProductIds)
            ->get()
            ->keyBy('id');

        foreach ($inputProductIds as $id) {
            $product = $products->get($id);
            if (! $product || ! in_array($product->type, [ProductType::RawMaterial, ProductType::Consumable], true)) {
                throw ValidationException::withMessages([
                    'inputs' => 'المدخلات يجب أن تكون مواد خام أو منتجات استهلاكية فقط',
                ]);
            }
        }

        foreach ($outputProductIds as $id) {
            $product = $products->get($id);
            if (! $product || ! in_array($product->type, [ProductType::RawMaterial, ProductType::Consumable], true)) {
                throw ValidationException::withMessages([
                    'outputs' => 'المخرجات يجب أن تكون مواد خام أو منتجات استهلاكية فقط',
                ]);
            }
        }
    }

    /**
     * Validate an existing ManufacturingRecipe model and its loaded/persisted relations.
     */
    public function validate(ManufacturingRecipe $recipe): void
    {
        $inputs = $recipe->inputs()->get()->map(fn ($input) => [
            'product_id' => $input->product_id,
            'quantity' => (float) $input->quantity,
        ])->all();

        $outputs = $recipe->outputs()->get()->map(fn ($output) => [
            'product_id' => $output->product_id,
            'is_main' => (bool) $output->is_main,
            'expected_quantity' => (float) $output->expected_quantity,
            'cost_method' => $output->cost_method ?? 'percent',
            'cost_value' => (float) ($output->cost_value ?? 0),
        ])->all();

        $this->validateData($inputs, $outputs);
    }

    /**
     * Create a manufacturing recipe along with its inputs and outputs.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): ManufacturingRecipe
    {
        $inputs = is_array($data['inputs'] ?? null) ? $data['inputs'] : [];
        $outputs = is_array($data['outputs'] ?? null) ? $data['outputs'] : [];

        $this->validateData($inputs, $outputs);

        return DB::transaction(function () use ($data, $inputs, $outputs) {
            $recipe = ManufacturingRecipe::create([
                'name' => (string) $data['name'],
                'notes' => $data['notes'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);

            foreach ($inputs as $input) {
                $recipe->inputs()->create([
                    'product_id' => (int) $input['product_id'],
                    'quantity' => (float) $input['quantity'],
                ]);
            }

            foreach ($outputs as $output) {
                $isMain = (bool) ($output['is_main'] ?? false);
                $recipe->outputs()->create([
                    'product_id' => (int) $output['product_id'],
                    'is_main' => $isMain,
                    'expected_quantity' => (float) $output['expected_quantity'],
                    'cost_method' => (string) ($output['cost_method'] ?? 'percent'),
                    'cost_value' => $isMain ? 0.0 : (float) ($output['cost_value'] ?? 0.0),
                ]);
            }

            return $recipe->load(['inputs', 'outputs', 'mainOutput']);
        });
    }

    /**
     * Update a manufacturing recipe along with its inputs and outputs.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(ManufacturingRecipe $recipe, array $data): ManufacturingRecipe
    {
        $inputs = is_array($data['inputs'] ?? null) ? $data['inputs'] : [];
        $outputs = is_array($data['outputs'] ?? null) ? $data['outputs'] : [];

        $this->validateData($inputs, $outputs);

        return DB::transaction(function () use ($recipe, $data, $inputs, $outputs) {
            $recipe->update([
                'name' => (string) ($data['name'] ?? $recipe->name),
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $recipe->notes,
                'is_active' => (bool) ($data['is_active'] ?? $recipe->is_active),
            ]);

            $recipe->inputs()->delete();
            $recipe->outputs()->delete();

            foreach ($inputs as $input) {
                $recipe->inputs()->create([
                    'product_id' => (int) $input['product_id'],
                    'quantity' => (float) $input['quantity'],
                ]);
            }

            foreach ($outputs as $output) {
                $isMain = (bool) ($output['is_main'] ?? false);
                $recipe->outputs()->create([
                    'product_id' => (int) $output['product_id'],
                    'is_main' => $isMain,
                    'expected_quantity' => (float) $output['expected_quantity'],
                    'cost_method' => (string) ($output['cost_method'] ?? 'percent'),
                    'cost_value' => $isMain ? 0.0 : (float) ($output['cost_value'] ?? 0.0),
                ]);
            }

            return $recipe->load(['inputs', 'outputs', 'mainOutput']);
        });
    }
}
