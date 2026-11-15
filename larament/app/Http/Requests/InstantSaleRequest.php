<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InstantSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_uuid' => ['required', 'uuid'],
            'type' => ['required', 'string', 'exists:order_types,code'],
            ...self::itemRules('items'),
            'discount' => ['nullable', 'array'],
            'discount.value' => ['required_with:discount', 'numeric', 'min:0'],
            'discount.type' => ['required_with:discount', 'in:percent,value'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'held_sale_id' => ['nullable', 'integer'],
            ...self::tenderRules(),
            'print' => ['boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function itemRules(string $key, string $presence = 'required'): array
    {
        return [
            $key => [$presence, 'array', 'min:1'],
            $key.'.*.product_id' => ['required', 'integer', 'exists:products,id'],
            $key.'.*.quantity' => ['required', 'numeric', 'min:0.001'],
            $key.'.*.notes' => ['nullable', 'string', 'max:500'],
            $key.'.*.item_discount' => ['nullable', 'numeric', 'min:0'],
            $key.'.*.item_discount_type' => ['nullable', 'string', 'in:percent,value'],
            $key.'.*.item_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function tenderRules(): array
    {
        return [
            'tenders' => ['present', 'array'],
            'tenders.cash' => ['nullable', 'numeric', 'min:0'],
            'tenders.card' => ['nullable', 'numeric', 'min:0'],
            'tenders.talabat_card' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function lines(array $items): array
    {
        return array_values(array_map(fn (array $line): array => array_intersect_key($line, array_flip([
            'product_id', 'quantity', 'notes', 'item_discount', 'item_discount_type', 'item_discount_percent',
        ])), $items));
    }

    /**
     * @param  array<string, mixed>|null  $tenders
     * @return array<string, float>
     */
    public static function tenders(?array $tenders): array
    {
        $kept = [];
        foreach (['cash', 'card', 'talabat_card'] as $method) {
            $amount = (float) ($tenders[$method] ?? 0);
            if ($amount > 0) {
                $kept[$method] = $amount;
            }
        }

        return $kept;
    }
}
