<?php

use App\DTOs\Orders\OrderItemDTO;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Repositories\OrderItemRepository;

uses(Tests\Unit\TestCase::class);

describe('OrderItemRepository', function () {
    beforeEach(function () {
        $this->repository = app(OrderItemRepository::class);
        $this->order = Order::factory()->create();
        $this->product = Product::factory()->create();
    });

    describe('createManyForOrder', function () {
        it('creates multiple order items', function () {
            $product2 = Product::factory()->create();

            $itemDTOs = [
                new OrderItemDTO($this->product->id, 2, 20.00, 10.00),
                new OrderItemDTO($product2->id, 1, 15.00, 8.00),
            ];

            $orderItems = $this->repository->createManyForOrder($this->order, $itemDTOs);

            expect($orderItems)->toHaveCount(2)
                ->and($orderItems->first()->product_id)->toBe($this->product->id)
                ->and($orderItems->last()->product_id)->toBe($product2->id);
        });

        it('creates items with correct totals', function () {
            $itemDTOs = [
                new OrderItemDTO($this->product->id, 3, 10.00, 5.00),
            ];

            $orderItems = $this->repository->createManyForOrder($this->order, $itemDTOs);

            expect($orderItems->first()->total)->toBe(30.00);
        });
    });

    describe('updateOrderItems', function () {
        it('replaces existing items with new ones', function () {
            // Create initial items
            OrderItem::factory()->create(['order_id' => $this->order->id]);
            OrderItem::factory()->create(['order_id' => $this->order->id]);

            expect($this->order->items()->count())->toBe(2);

            $newItemDTOs = [
                new OrderItemDTO($this->product->id, 1, 15.00, 8.00),
            ];

            $newItems = $this->repository->updateOrderItems($this->order, $newItemDTOs);

            expect($newItems)->toHaveCount(1)
                ->and($this->order->items()->count())->toBe(1)
                ->and($newItems->first()->product_id)->toBe($this->product->id);
        });

        it('can clear all items', function () {
            OrderItem::factory()->create(['order_id' => $this->order->id]);

            $newItems = $this->repository->updateOrderItems($this->order, []);

            expect($newItems)->toHaveCount(0)
                ->and($this->order->items()->count())->toBe(0);
        });
    });

    describe('deleteOrderItems', function () {
        it('deletes all order items', function () {
            OrderItem::factory()->create(['order_id' => $this->order->id]);
            OrderItem::factory()->create(['order_id' => $this->order->id]);

            $result = $this->repository->deleteOrderItems($this->order);

            expect($result)->toBeTrue()
                ->and($this->order->items()->count())->toBe(0);
        });

        it('returns true when no items to delete', function () {
            $result = $this->repository->deleteOrderItems($this->order);

            expect($result)->toBeTrue();
        });
    });
});
