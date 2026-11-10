<?php

namespace App\Costing;

use App\Models\Product;
use App\Models\ProductCostHistory;
use App\Services\SettingsService;
use Closure;
use Illuminate\Database\Eloquent\Model;

class CostHistoryRecorder
{
    private ?CostingMethod $method = null;

    private ?Model $source = null;

    public function record(Product $p, float $old, float $new, CostingMethod $m, ?Model $source): void
    {
        if (round($old, 4) === round($new, 4)) {
            return;
        }

        ProductCostHistory::create([
            'product_id' => $p->id,
            'old_cost' => $old,
            'new_cost' => $new,
            'method' => $m,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'user_id' => auth()->id(),
        ]);
    }

    public function recordSaved(Product $product): void
    {
        if ($product->getRawOriginal('cost') !== null && $product->wasChanged('cost')) {
            $this->record($product, (float) $product->getRawOriginal('cost'), (float) $product->cost,
                $this->method ?? app(SettingsService::class)->getCostingMethod(), $this->source);
        }
    }

    public function during(CostingMethod $method, ?Model $source, Closure $callback): void
    {
        $previousMethod = $this->method;
        $previousSource = $this->source;
        $this->method = $method;
        $this->source = $source;

        try {
            $callback();
        } finally {
            $this->method = $previousMethod;
            $this->source = $previousSource;
        }
    }
}
