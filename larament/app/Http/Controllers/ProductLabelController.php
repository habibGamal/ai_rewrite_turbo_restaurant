<?php

namespace App\Http\Controllers;

use App\Enums\SettingKey;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\FeatureService;
use App\Services\Products\ProductGroupGridService;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Printable barcode labels for a product group's variants: one label per
 * SKU, or as many as are in stock (for tagging a delivery). The page prints
 * itself; label size is set for small thermal label rolls.
 */
class ProductLabelController extends Controller
{
    public function group(Request $request, ProductGroup $group, ProductGroupGridService $grid): View
    {
        abort_unless(app(FeatureService::class)->variantsEnabled(), 404);
        Gate::authorize('update', $group);

        $validated = $request->validate([
            'copies' => ['nullable', 'in:one,stock'],
            'warehouse_id' => ['nullable', 'integer'],
        ]);
        $byStock = ($validated['copies'] ?? 'one') === 'stock';
        $stock = $byStock ? $grid->stock($group, $validated['warehouse_id'] ?? null) : [];

        $labels = [];
        foreach ($group->variants()->where('is_active', true)->orderBy('name')->get() as $variant) {
            /** @var Product $variant */
            if ($variant->barcode === null || $variant->barcode === '') {
                continue;
            }

            $copies = $byStock ? max(0, (int) floor($stock[$variant->id] ?? 0)) : 1;
            for ($i = 0; $i < $copies; $i++) {
                $labels[] = $variant;
            }
        }

        return view('print.product-labels', [
            'group' => $group,
            'labels' => $labels,
            'missingBarcodes' => $group->variants()->where(fn ($q) => $q->whereNull('barcode')->orWhere('barcode', ''))->count(),
            'shopName' => app(SettingsService::class)->get(SettingKey::RESTAURANT_NAME->value, ''),
        ]);
    }
}
