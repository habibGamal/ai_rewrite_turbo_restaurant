<?php

namespace App\Costing;

use App\Enums\SettingKey;
use App\Models\Product;
use App\Models\ProductCostHistory;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Services\ShiftService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CostingMethodSwitcher
{
    public function __construct(private SettingsService $settings, private ShiftService $shifts) {}

    public function switchTo(CostingMethod $m): void
    {
        DB::transaction(function () use ($m) {
            $setting = Setting::where('key', SettingKey::COSTING_METHOD->value)->lockForUpdate()->first();

            if (($setting->value ?? SettingKey::COSTING_METHOD->defaultValue()) === $m->value) {
                return;
            }

            if ($this->shifts->getCurrentShift() !== null) {
                throw ValidationException::withMessages([
                    'data.'.SettingKey::COSTING_METHOD->value => 'لا يمكن تغيير طريقة تقييم التكلفة أثناء وجود وردية مفتوحة',
                ]);
            }

            $this->settings->set(SettingKey::COSTING_METHOD->value, $m->value);
            Product::orderBy('id')->lockForUpdate()->each(function (Product $product) use ($m) {
                ProductCostHistory::create([
                    'product_id' => $product->id,
                    'old_cost' => $product->cost,
                    'new_cost' => $product->cost,
                    'method' => $m,
                    'user_id' => auth()->id(),
                ]);
            });
        });

        Cache::forget('settings.all');
    }
}
