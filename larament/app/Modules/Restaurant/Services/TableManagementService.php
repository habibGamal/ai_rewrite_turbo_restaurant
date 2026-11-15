<?php

namespace App\Modules\Restaurant\Services;

use App\Exceptions\OrderException;
use App\Models\DineTable;

class TableManagementService
{
    public function validateTableAvailability(string $tableNumber, string $typeCode = 'dine_in'): void
    {
        if (! $this->isTableAvailable($tableNumber, $typeCode)) {
            throw new OrderException('هذه الطاولة محجوزة');
        }
    }

    public function isTableAvailable(string $tableNumber, string $typeCode = 'dine_in'): bool
    {
        $table = DineTable::where('order_type_code', $typeCode)->where('table_number', $tableNumber)->first();

        return ! $table || $table->order_id === null;
    }

    public function reserveTable(string $tableNumber, int $orderId, string $typeCode = 'dine_in'): DineTable
    {
        $this->validateTableAvailability($tableNumber, $typeCode);

        $table = DineTable::firstOrCreate(['order_type_code' => $typeCode, 'table_number' => $tableNumber]);
        if (! DineTable::whereKey($table->id)->whereNull('order_id')->update(['order_id' => $orderId])) {
            throw new OrderException('هذه الطاولة محجوزة');
        }
        $table->refresh();

        return $table;
    }

    public function freeTable(string $tableNumber, string $typeCode = 'dine_in'): void
    {
        DineTable::where('order_type_code', $typeCode)->where('table_number', $tableNumber)
            ->update(['order_id' => null]);
    }

    public function getAvailableTables(string $typeCode = 'dine_in'): array
    {
        return DineTable::where('order_type_code', $typeCode)->whereNull('order_id')
            ->pluck('table_number')
            ->toArray();
    }

    public function getOccupiedTables(string $typeCode = 'dine_in'): array
    {
        return DineTable::where('order_type_code', $typeCode)->whereNotNull('order_id')
            ->with('order')
            ->get()
            ->toArray();
    }
}
