<?php

namespace App\Modules\Restaurant\Behaviors;

use App\Enums\SettingKey;
use App\Exceptions\OrderException;
use App\Models\DineTable;
use App\Models\Order;
use App\Models\OrderTypeRow;
use App\Orders\Behaviors\OrderTypeBehavior;
use App\Orders\Behaviors\ProvidesTabData;
use App\Orders\OrderDisplay;
use App\Orders\OrderOpening;
use App\Orders\TabPresentation;
use App\Modules\Restaurant\Services\FloorLayoutService;
use App\Modules\Restaurant\Services\TableManagementService;

final class DineInBehavior implements OrderTypeBehavior, ProvidesTabData
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

    /**
     * The floor tab gets its layout and the tables taken right now.
     *
     * @return array<string, mixed>
     */
    public function tabData(OrderTypeRow $type): array
    {
        if (! $this->floor->isEnabledWithTables($type->code)) {
            return [];
        }

        return [
            'layout' => $this->floor->read($type->code),
            'occupancy' => DineTable::query()
                ->where('order_type_code', $type->code)
                ->whereNotNull('order_id')
                ->with('order')
                ->get()
                ->filter(fn (DineTable $table) => $table->order !== null)
                ->mapWithKeys(fn (DineTable $table) => [$table->table_number => [
                    'id' => $table->order->id,
                    'order_number' => $table->order->order_number,
                    'created_at' => $table->order->created_at,
                ]])
                ->all(),
        ];
    }
}
