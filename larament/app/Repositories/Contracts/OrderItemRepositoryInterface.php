<?php

namespace App\Repositories\Contracts;

use App\Models\Order;
use Illuminate\Database\Eloquent\Collection;

interface OrderItemRepositoryInterface
{
    public function createManyForOrder(Order $order, array $itemDTOs): Collection;

    public function updateOrderItems(Order $order, array $itemDTOs): Collection;

    public function deleteOrderItems(Order $order): bool;
}
