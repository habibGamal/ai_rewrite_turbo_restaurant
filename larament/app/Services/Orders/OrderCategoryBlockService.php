<?php

namespace App\Services\Orders;

use App\Exceptions\OrderException;
use App\Models\Category;
use App\Models\Product;

class OrderCategoryBlockService
{
    /**
     * @param  list<int>  $productIds
     */
    public function assertCanAdd(string $orderTypeCode, array $productIds): void
    {
        if ($productIds === []) {
            return;
        }

        $blocked = Product::query()
            ->with(['category.blockedOrderTypes', 'category.parent.blockedOrderTypes'])
            ->whereIn('id', $productIds)
            ->get()
            ->filter(function (Product $product) use ($orderTypeCode): bool {
                $category = $product->category;

                return $category instanceof Category && $category->isBlockedFor($orderTypeCode);
            });

        if ($blocked->isEmpty()) {
            return;
        }

        throw new OrderException(
            'لا يمكن إضافة صنف من تصنيف محظور لنوع الطلب الحالي: '.$blocked->pluck('name')->implode('، ')
        );
    }
}
