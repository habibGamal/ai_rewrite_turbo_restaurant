<?php

namespace App\Repositories;

use App\Models\Order;
use App\Repositories\Contracts\OrderItemRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class OrderItemRepository implements OrderItemRepositoryInterface
{
    public function createManyForOrder(Order $order, array $itemDTOs): Collection
    {
        $itemsData = array_map(fn ($itemDTO) => $itemDTO->toArray(), $itemDTOs);

        return $order->items()->createMany($itemsData);
    }

    public function updateOrderItems(Order $order, array $itemDTOs): Collection
    {
        // Delete existing items
        $this->deleteOrderItems($order);

        // Create new items
        return $this->createManyForOrder($order, $itemDTOs);
    }

    public function deleteOrderItems(Order $order): bool
    {
        return $order->items()->delete() >= 0;
    }
}
