<?php

use App\DTOs\Orders\CreateOrderDTO;
use App\Enums\OrderType;
use App\Orders\OrderOpening;

describe('CreateOrderDTO', function () {
    it('can be created with valid data', function () {
        $dto = new CreateOrderDTO(
            typeCode: OrderType::DINE_IN->value,
            shiftId: 1,
            userId: 1,
            opening: new OrderOpening('T001'),
        );

        expect($dto->typeCode)->toBe(OrderType::DINE_IN->value)
            ->and($dto->shiftId)->toBe(1)
            ->and($dto->userId)->toBe(1)
            ->and($dto->opening->tableNumber)->toBe('T001');
    });

    it('allows null table number for takeaway orders', function () {
        $dto = new CreateOrderDTO(
            typeCode: OrderType::TAKEAWAY->value,
            shiftId: 1,
            userId: 1,
        );

        expect($dto->opening->tableNumber)->toBeNull();
    });

    it('allows null table number for delivery orders', function () {
        $dto = new CreateOrderDTO(
            typeCode: OrderType::DELIVERY->value,
            shiftId: 1,
            userId: 1,
            driverId: 1,
        );

        expect($dto->opening->tableNumber)->toBeNull()
            ->and($dto->driverId)->toBe(1);
    });
});
