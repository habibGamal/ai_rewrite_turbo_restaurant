<?php

namespace App\Services\Kitchen;

use App\Enums\OrderType;
use App\Enums\SettingKey;
use App\Models\Category;
use App\Models\Product;

class KitchenRoutingService
{
    /**
     * Product station wins, then the category station, otherwise none.
     */
    public function stationId(Product $product): ?int
    {
        return $product->kitchen_station_id ?? $this->category($product)?->kitchen_station_id;
    }

    /**
     * Product flag wins, then the category flag, otherwise shown.
     */
    public function shownOnKds(Product $product): bool
    {
        if ($product->show_on_kds !== null) {
            return $product->show_on_kds;
        }

        $category = $this->category($product);

        return $category instanceof Category ? $category->show_on_kds : true;
    }

    /**
     * Product minutes, then category, then the order-type override, then the default.
     */
    public function targetMinutes(Product $product, OrderType $orderType): int
    {
        if ($product->prep_minutes !== null) {
            return $product->prep_minutes;
        }

        $category = $this->category($product);
        if ($category instanceof Category && $category->prep_minutes !== null) {
            return $category->prep_minutes;
        }

        $override = $this->orderTypeTarget($orderType);
        if ($override !== null) {
            return $override;
        }

        return (int) setting(SettingKey::KDS_DEFAULT_PREP_MINUTES);
    }

    private function orderTypeTarget(OrderType $orderType): ?int
    {
        $decoded = json_decode(setting(SettingKey::KDS_ORDER_TYPE_TARGETS), true);
        if (! is_array($decoded) || ! array_key_exists($orderType->value, $decoded)) {
            return null;
        }

        $value = $decoded[$orderType->value];
        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function category(Product $product): ?Category
    {
        $product->loadMissing('category');
        $category = $product->category;

        return $category instanceof Category ? $category : null;
    }
}
