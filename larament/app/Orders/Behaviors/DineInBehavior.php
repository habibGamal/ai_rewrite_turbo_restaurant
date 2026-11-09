<?php

namespace App\Orders\Behaviors;

use App\Enums\SettingKey;
use App\Exceptions\OrderException;
use App\Models\Order;
use App\Models\OrderTypeRow;
use App\Orders\OrderDisplay;
use App\Orders\OrderOpening;
use App\Orders\TabPresentation;
use App\Services\Orders\FloorLayoutService;
use App\Services\Orders\TableManagementService;

final class DineInBehavior implements OrderTypeBehavior
{
    public function __construct(
        private readonly TableManagementService $tables,
        private readonly FloorLayoutService $floor,
    ) {}

    public function cashierCanCreate(): bool
    {
        return true;
    }

    public function recalculatesTotals(): bool
    {
        return true;
    }

    public function serviceAmount(Order $order, float $subtotal): float
    {
        return $subtotal * (float) setting(SettingKey::DINE_IN_SERVICE_CHARGE);
    }

    public function onOpened(Order $order, OrderOpening $opening): void
    {
        $tableNumber = $opening->tableNumber;

        if ($tableNumber === null || $tableNumber === '') {
            throw new OrderException('رقم الطاولة مطلوب');
        }

        $this->tables->reserveTable($tableNumber, $order->id, $order->type);
    }

    public function onClosed(Order $order): void
    {
        if ($order->dine_table_number !== null && $order->dine_table_number !== '') {
            $this->tables->freeTable($order->dine_table_number, $order->type);
        }
    }

    public function carriesAcrossShifts(): bool
    {
        return false;
    }

    public function usesPosOrderNumber(): bool
    {
        return true;
    }

    public function display(OrderTypeRow $type): OrderDisplay
    {
        return OrderDisplay::forRow($type, showTable: true, chargesService: true);
    }

    public function presentation(OrderTypeRow $type): TabPresentation
    {
        return new TabPresentation($this->floor->isEnabledWithTables($type->code) ? 'floor' : 'cards', 'choose_table', ['processing'], 'table');
    }
}
