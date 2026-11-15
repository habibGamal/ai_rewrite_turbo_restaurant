<?php

namespace App\Services\Orders;

use App\Core\Events\OrderOpened;
use App\DTOs\Orders\CreateOrderDTO;
use App\Models\Order;
use App\Orders\Behaviors\OrderTypeBehavior;
use App\Orders\OrderTypeCatalog;
use App\Repositories\OrderRepository;
use Illuminate\Support\Facades\DB;

class OrderOpeningService
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly OrderTypeCatalog $catalog,
    ) {}

    public function open(CreateOrderDTO $dto): Order
    {
        $behavior = $this->catalog->behaviorFor($dto->typeCode);

        $order = DB::transaction(function () use ($dto, $behavior) {
            $excludedCodes = $this->catalog->codesWhere(
                fn (OrderTypeBehavior $candidate): bool => ! $candidate->usesPosOrderNumber()
            );
            $orderNumber = $this->orderRepository->getNextOrderNumber($dto->shiftId, $excludedCodes);
            $created = $this->orderRepository->create($dto, $orderNumber);
            $behavior->onOpened($created, $dto->opening);
            OrderOpened::dispatch($created, $dto->opening);

            return $created;
        });

        return $order;
    }
}
