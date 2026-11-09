<?php

namespace App\Services\Manufacturing;

use App\Exceptions\ManufacturingException;
use BackedEnum;

class OutputCostAllocator
{
    /**
     * Allocate the input cost pot across manufacturing outputs.
     *
     * @param  array<int, array{key: string|int, is_main: bool, cost_method?: string|BackedEnum|null, cost_value?: float|int|null, actual_quantity: float|int}>  $outputs
     * @return array<string|int, array{allocated_cost: float, unit_cost: float}>
     */
    public static function allocate(float $pot, array $outputs): array
    {
        $pot = round($pot, 2);

        $mainOutputs = [];
        $fixedOutputs = [];
        $percentOutputs = [];
        $results = [];

        foreach ($outputs as $output) {
            $key = $output['key'];
            $isMain = (bool) $output['is_main'];
            $actualQuantity = (float) $output['actual_quantity'];
            $rawMethod = $output['cost_method'] ?? 'percent';
            $costMethod = $rawMethod instanceof BackedEnum ? (string) $rawMethod->value : (string) $rawMethod;
            $costValue = (float) ($output['cost_value'] ?? 0.0);

            if ($actualQuantity < 0) {
                throw new ManufacturingException('لا يمكن أن تكون الكمية الفعلية للمخرجات سالبة');
            }

            $results[$key] = [
                'allocated_cost' => 0.0,
                'unit_cost' => 0.0,
            ];

            $normalized = [
                'key' => $key,
                'is_main' => $isMain,
                'cost_method' => $costMethod,
                'cost_value' => $costValue,
                'actual_quantity' => $actualQuantity,
            ];

            if ($isMain) {
                $mainOutputs[] = $normalized;
            } elseif ($costMethod === 'fixed') {
                $fixedOutputs[] = $normalized;
            } else {
                $percentOutputs[] = $normalized;
            }
        }

        if (count($mainOutputs) !== 1) {
            throw new ManufacturingException('يجب تحديد مخرج رئيسي واحد بالضبط');
        }

        $mainOutput = $mainOutputs[0];
        if ($mainOutput['actual_quantity'] <= 0) {
            throw new ManufacturingException('يجب أن تكون الكمية الفعلية للمخرج الرئيسي أكبر من صفر');
        }

        $totalFixedCost = 0.0;
        foreach ($fixedOutputs as $fixed) {
            $key = $fixed['key'];
            $qty = $fixed['actual_quantity'];

            if ($qty <= 0) {
                $results[$key] = [
                    'allocated_cost' => 0.0,
                    'unit_cost' => 0.0,
                ];

                continue;
            }

            $allocated = round($qty * $fixed['cost_value'], 2);
            $totalFixedCost = round($totalFixedCost + $allocated, 2);

            $results[$key] = [
                'allocated_cost' => $allocated,
                'unit_cost' => round($allocated / $qty, 4),
            ];
        }

        if ($totalFixedCost > $pot) {
            throw new ManufacturingException('إجمالي تكلفة المخرجات الثابتة يتجاوز إجمالي تكلفة المدخلات');
        }

        $remainingAfterFixed = round($pot - $totalFixedCost, 2);
        $totalPercentCost = 0.0;

        foreach ($percentOutputs as $percent) {
            $key = $percent['key'];
            $qty = $percent['actual_quantity'];

            if ($qty <= 0) {
                $results[$key] = [
                    'allocated_cost' => 0.0,
                    'unit_cost' => 0.0,
                ];

                continue;
            }

            $allocated = round($remainingAfterFixed * ($percent['cost_value'] / 100.0), 2);
            $totalPercentCost = round($totalPercentCost + $allocated, 2);

            $results[$key] = [
                'allocated_cost' => $allocated,
                'unit_cost' => round($allocated / $qty, 4),
            ];
        }

        $mainAllocated = round($pot - $totalFixedCost - $totalPercentCost, 2);

        if ($mainAllocated < 0) {
            throw new ManufacturingException('إجمالي تكلفة المخرجات الفرعية يتجاوز إجمالي تكلفة المدخلات');
        }

        $mainKey = $mainOutput['key'];
        $mainQty = $mainOutput['actual_quantity'];

        $results[$mainKey] = [
            'allocated_cost' => $mainAllocated,
            'unit_cost' => round($mainAllocated / $mainQty, 4),
        ];

        return $results;
    }
}
