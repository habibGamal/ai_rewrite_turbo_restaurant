<?php

namespace App\Services\Orders;

use App\DTOs\Orders\CreateOrderDTO;
use App\Enums\OrderType;
use App\Events\Orders\OrderCreated;
use App\Exceptions\OrderException;
use App\Models\Order;
use App\Repositories\OrderRepository;

class OrderOpeningService
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly TableManagementService $tableManagementService,
    ) {}

    public function open(CreateOrderDTO $dto): Order
    {
        $requiresTable = OrderType::from($dto->typeCode)->requiresTable();
        $tableNumber = $dto->opening->tableNumber;

        if ($requiresTable && ($tableNumber === null || $tableNumber === '')) {
            throw new OrderException('رقم الطاولة مطلوب');
        }

        $order = $this->orderRepository->create($dto);

        if ($requiresTable) {
            $this->tableManagementService->reserveTable((string) $tableNumber, $order->id);
        }

        OrderCreated::dispatch($order);

        return $order;
    }
}
