<?php

namespace App\Services\Manufacturing;

use App\Exceptions\ManufacturingException;
use App\Models\Product;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class ManufacturingCostPreviewService
{
    /**
     * Preview recipe costs based on inputs and expected outputs.
     *
     * @param  array<int|string, array<string, mixed>>  $inputs
     * @param  array<int|string, array<string, mixed>>  $extraOutputs
     * @return array{batch_cost: float, outputs: array<int, array{product_id: int, product_name: string, unit: string, is_main: bool, expected_quantity: float, allocated_cost: float, unit_cost: float}>, error: ?string}
     */
    public function previewRecipe(array $inputs, ?int $mainProductId, float|int|string|null $batchQuantity, array $extraOutputs): array
    {
        $batchCost = 0.0;
        foreach ($inputs as $input) {
            $pid = (int) ($input['product_id'] ?? 0);
            $qty = (float) ($input['quantity'] ?? 0);
            if ($pid > 0 && $qty > 0) {
                $p = Product::find($pid);
                $cost = (float) ($p->cost ?? 0);
                $batchCost += round($qty * $cost, 2);
            }
        }
        $batchCost = round($batchCost, 2);

        $mainQty = (float) ($batchQuantity ?? 0);
        if (! $mainProductId || $mainQty <= 0) {
            return [
                'batch_cost' => $batchCost,
                'outputs' => [],
                'error' => null,
            ];
        }

        $mainProduct = Product::find($mainProductId);
        $outputsForAllocation = [
            [
                'key' => 'main',
                'product_id' => $mainProductId,
                'product_name' => $mainProduct->name ?? 'المنتج الرئيسي',
                'unit' => $mainProduct ? Product::formatUnit($mainProduct->unit) : '',
                'is_main' => true,
                'cost_method' => 'percent',
                'cost_value' => 0.0,
                'actual_quantity' => $mainQty,
            ],
        ];

        foreach ($extraOutputs as $idx => $extra) {
            $pid = (int) ($extra['product_id'] ?? 0);
            $qty = (float) ($extra['expected_quantity'] ?? 0);
            if ($pid > 0) {
                $p = Product::find($pid);
                $outputsForAllocation[] = [
                    'key' => 'extra_'.$idx,
                    'product_id' => $pid,
                    'product_name' => $p->name ?? '',
                    'unit' => $p ? Product::formatUnit($p->unit) : '',
                    'is_main' => false,
                    'cost_method' => (string) ($extra['cost_method'] ?? 'percent'),
                    'cost_value' => (float) ($extra['cost_value'] ?? 0.0),
                    'actual_quantity' => $qty,
                ];
            }
        }

        try {
            $allocated = OutputCostAllocationService::allocate($batchCost, $outputsForAllocation);
            $resultOutputs = [];
            foreach ($outputsForAllocation as $item) {
                $alloc = $allocated[$item['key']];
                $resultOutputs[] = [
                    'product_id' => $item['product_id'],
                    'product_name' => $item['product_name'],
                    'unit' => $item['unit'],
                    'is_main' => $item['is_main'],
                    'expected_quantity' => $item['actual_quantity'],
                    'allocated_cost' => $alloc['allocated_cost'],
                    'unit_cost' => $alloc['unit_cost'],
                ];
            }

            return [
                'batch_cost' => $batchCost,
                'outputs' => $resultOutputs,
                'error' => null,
            ];
        } catch (ManufacturingException $e) {
            return [
                'batch_cost' => $batchCost,
                'outputs' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Preview manufacturing order costs based on actual inputs and actual outputs.
     *
     * @param  array<int|string, array<string, mixed>>  $inputs
     * @param  array<int|string, array<string, mixed>>  $outputs
     * @return array{total_input_cost: float, outputs: array<int, array{product_id: int, product_name: string, unit: string, is_main: bool, actual_quantity: float, allocated_cost: float, unit_cost: float}>, error: ?string}
     */
    public function previewOrder(array $inputs, array $outputs): array
    {
        $totalInputCost = 0.0;
        foreach ($inputs as $input) {
            $pid = (int) ($input['product_id'] ?? 0);
            $qty = (float) ($input['actual_quantity'] ?? 0);
            if ($pid > 0 && $qty > 0) {
                $unitCost = (float) ($input['unit_cost'] ?? 0);
                if ($unitCost <= 0) {
                    $p = Product::find($pid);
                    $unitCost = (float) ($p->cost ?? 0);
                }
                $totalInputCost += round($qty * $unitCost, 2);
            }
        }
        $totalInputCost = round($totalInputCost, 2);

        $outputsForAllocation = [];
        $mainFound = false;
        foreach ($outputs as $idx => $output) {
            $pid = (int) ($output['product_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $isMain = (bool) ($output['is_main'] ?? false);
            if ($isMain) {
                $mainFound = true;
            }
            $p = Product::find($pid);
            $qty = (float) ($output['actual_quantity'] ?? 0);
            $outputsForAllocation[] = [
                'key' => $idx,
                'product_id' => $pid,
                'product_name' => $p->name ?? '',
                'unit' => $p ? Product::formatUnit($p->unit) : '',
                'is_main' => $isMain,
                'cost_method' => (string) ($output['cost_method'] ?? 'percent'),
                'cost_value' => (float) ($output['cost_value'] ?? 0.0),
                'actual_quantity' => $qty,
            ];
        }

        if (! $mainFound || count($outputsForAllocation) === 0) {
            return [
                'total_input_cost' => $totalInputCost,
                'outputs' => [],
                'error' => null,
            ];
        }

        try {
            $allocated = OutputCostAllocationService::allocate($totalInputCost, $outputsForAllocation);
            $resultOutputs = [];
            foreach ($outputsForAllocation as $item) {
                $alloc = $allocated[$item['key']];
                $resultOutputs[] = [
                    'product_id' => $item['product_id'],
                    'product_name' => $item['product_name'],
                    'unit' => $item['unit'],
                    'is_main' => $item['is_main'],
                    'actual_quantity' => $item['actual_quantity'],
                    'allocated_cost' => $alloc['allocated_cost'],
                    'unit_cost' => $alloc['unit_cost'],
                ];
            }

            return [
                'total_input_cost' => $totalInputCost,
                'outputs' => $resultOutputs,
                'error' => null,
            ];
        } catch (ManufacturingException $e) {
            return [
                'total_input_cost' => $totalInputCost,
                'outputs' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Render the recipe cost preview as HTML.
     *
     * @param  array{batch_cost: float, outputs: array<int, array{product_id: int, product_name: string, unit: string, is_main: bool, expected_quantity: float, allocated_cost: float, unit_cost: float}>, error: ?string}  $preview
     */
    public function renderRecipePreviewHtml(array $preview): Htmlable
    {
        $html = '<div class="space-y-3">';

        if (! empty($preview['error'])) {
            $escapedError = htmlspecialchars($preview['error'], ENT_QUOTES, 'UTF-8');
            $html .= '<div class="p-3 text-sm text-red-700 bg-red-100 dark:bg-red-900/40 dark:text-red-300 rounded-lg border border-red-200 dark:border-red-800">';
            $html .= '<strong>تعذر توزيع التكلفة:</strong> '.$escapedError;
            $html .= '</div>';
            $html .= '</div>';

            return new HtmlString($html);
        }

        if (empty($preview['outputs'])) {
            $html .= '<div class="text-sm text-gray-500 dark:text-gray-400">حدد المنتج الرئيسي وكمية الدفعة والمدخلات لمعاينة توزيع التكلفة.</div>';
            $html .= '</div>';

            return new HtmlString($html);
        }

        $html .= '<div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">';
        $html .= '<table class="w-full text-sm text-right">';
        $html .= '<thead class="bg-gray-50 dark:bg-gray-800 text-gray-700 dark:text-gray-300 font-semibold border-b border-gray-200 dark:border-gray-700">';
        $html .= '<tr>';
        $html .= '<th class="px-3 py-2">المنتج</th>';
        $html .= '<th class="px-3 py-2">النوع</th>';
        $html .= '<th class="px-3 py-2">الكمية المتوقعة</th>';
        $html .= '<th class="px-3 py-2">التكلفة المخصصة</th>';
        $html .= '<th class="px-3 py-2">تكلفة الوحدة المتوقعة</th>';
        $html .= '</tr>';
        $html .= '</thead>';
        $html .= '<tbody class="divide-y divide-gray-200 dark:divide-gray-700">';

        foreach ($preview['outputs'] as $out) {
            $badge = $out['is_main']
                ? '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-primary-100 text-primary-800 dark:bg-primary-900 dark:text-primary-200">رئيسي</span>'
                : '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200">إضافي</span>';

            $html .= '<tr class="'.($out['is_main'] ? 'bg-primary-50/30 dark:bg-primary-950/20 font-medium' : '').'">';
            $html .= '<td class="px-3 py-2">'.htmlspecialchars($out['product_name'], ENT_QUOTES, 'UTF-8').'</td>';
            $html .= '<td class="px-3 py-2">'.$badge.'</td>';
            $html .= '<td class="px-3 py-2">'.number_format($out['expected_quantity'], 3).' '.htmlspecialchars($out['unit'], ENT_QUOTES, 'UTF-8').'</td>';
            $html .= '<td class="px-3 py-2">'.number_format($out['allocated_cost'], 2).' ج.م</td>';
            $html .= '<td class="px-3 py-2 font-semibold">'.number_format($out['unit_cost'], 4).' ج.م/وحدة</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody>';
        $html .= '</table>';
        $html .= '</div>';
        $html .= '</div>';

        return new HtmlString($html);
    }

    /**
     * Render the order summary preview as HTML.
     *
     * @param  array{total_input_cost: float, outputs: array<int, array{product_id: int, product_name: string, unit: string, is_main: bool, actual_quantity: float, allocated_cost: float, unit_cost: float}>, error: ?string}  $preview
     */
    public function renderOrderSummaryHtml(array $preview): Htmlable
    {
        $html = '<div class="space-y-3">';

        $html .= '<div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">';
        $html .= '<span class="text-sm font-medium text-gray-700 dark:text-gray-300">إجمالي تكلفة المدخلات الفعلية:</span>';
        $html .= '<span class="text-base font-bold text-gray-900 dark:text-white">'.number_format($preview['total_input_cost'], 2).' ج.م</span>';
        $html .= '</div>';

        if (! empty($preview['error'])) {
            $escapedError = htmlspecialchars($preview['error'], ENT_QUOTES, 'UTF-8');
            $html .= '<div class="p-3 text-sm text-red-700 bg-red-100 dark:bg-red-900/40 dark:text-red-300 rounded-lg border border-red-200 dark:border-red-800">';
            $html .= '<strong>تعذر توزيع التكلفة التقديرية:</strong> '.$escapedError;
            $html .= '</div>';
            $html .= '</div>';

            return new HtmlString($html);
        }

        if (empty($preview['outputs'])) {
            $html .= '<div class="text-sm text-gray-500 dark:text-gray-400">حدد الكميات الفعلية للمخرجات لمعاينة التكلفة المخصصة.</div>';
            $html .= '</div>';

            return new HtmlString($html);
        }

        $html .= '<div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">';
        $html .= '<table class="w-full text-sm text-right">';
        $html .= '<thead class="bg-gray-50 dark:bg-gray-800 text-gray-700 dark:text-gray-300 font-semibold border-b border-gray-200 dark:border-gray-700">';
        $html .= '<tr>';
        $html .= '<th class="px-3 py-2">المنتج</th>';
        $html .= '<th class="px-3 py-2">النوع</th>';
        $html .= '<th class="px-3 py-2">الكمية الفعلية</th>';
        $html .= '<th class="px-3 py-2">التكلفة المخصصة التقديرية</th>';
        $html .= '<th class="px-3 py-2">تكلفة الوحدة التقديرية</th>';
        $html .= '</tr>';
        $html .= '</thead>';
        $html .= '<tbody class="divide-y divide-gray-200 dark:divide-gray-700">';

        foreach ($preview['outputs'] as $out) {
            $badge = $out['is_main']
                ? '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-primary-100 text-primary-800 dark:bg-primary-900 dark:text-primary-200">رئيسي</span>'
                : '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200">إضافي</span>';

            $html .= '<tr class="'.($out['is_main'] ? 'bg-primary-50/30 dark:bg-primary-950/20 font-medium' : '').'">';
            $html .= '<td class="px-3 py-2">'.htmlspecialchars($out['product_name'], ENT_QUOTES, 'UTF-8').'</td>';
            $html .= '<td class="px-3 py-2">'.$badge.'</td>';
            $html .= '<td class="px-3 py-2">'.number_format($out['actual_quantity'], 3).' '.htmlspecialchars($out['unit'], ENT_QUOTES, 'UTF-8').'</td>';
            $html .= '<td class="px-3 py-2">'.number_format($out['allocated_cost'], 2).' ج.م</td>';
            $html .= '<td class="px-3 py-2 font-semibold">'.number_format($out['unit_cost'], 4).' ج.م/وحدة</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody>';
        $html .= '</table>';
        $html .= '</div>';
        $html .= '</div>';

        return new HtmlString($html);
    }
}
