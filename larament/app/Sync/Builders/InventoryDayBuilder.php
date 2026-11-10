<?php

namespace App\Sync\Builders;

use App\Models\InventoryItemMovementDaily;
use App\Sync\DocumentType;
use App\Sync\Payload;

class InventoryDayBuilder implements DocumentBuilder
{
    protected ?string $date = null;

    public function type(): DocumentType
    {
        return DocumentType::InventoryDay;
    }

    /**
     * Set the business date key for this document.
     */
    public function withKey(?string $key): static
    {
        $this->date = $key;

        return $this;
    }

    /**
     * Build the inventory_day payload snapshot for the date stored via withKey().
     *
     * @return array<string, mixed>|null
     */
    public function build(string $uuid): ?array
    {
        if ($this->date === null) {
            return null;
        }

        $date = Payload::date($this->date);
        if ($date === null) {
            return null;
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, InventoryItemMovementDaily> $records */
        $records = InventoryItemMovementDaily::query()
            ->with('product')
            ->whereDate('date', $date)
            ->orderBy('id', 'asc')
            ->get();

        if ($records->isEmpty()) {
            return null;
        }

        $rows = [];
        foreach ($records->groupBy('product_id') as $productRecords) {
            /** @var InventoryItemMovementDaily $first */
            $first = $productRecords->first();

            $rows[] = [
                'product_uuid' => $first->product ? (string) $first->product->getAttribute('uuid') : '',
                'start_qty' => Payload::qty($productRecords->sum(fn (InventoryItemMovementDaily $r) => (float) $r->start_quantity)),
                'incoming_qty' => Payload::qty($productRecords->sum(fn (InventoryItemMovementDaily $r) => (float) $r->incoming_quantity)),
                'sales_qty' => Payload::qty($productRecords->sum(fn (InventoryItemMovementDaily $r) => (float) $r->sales_quantity)),
                'return_sales_qty' => Payload::qty($productRecords->sum(fn (InventoryItemMovementDaily $r) => (float) $r->return_sales_quantity)),
                'waste_qty' => Payload::qty($productRecords->sum(fn (InventoryItemMovementDaily $r) => (float) $r->return_waste_quantity)),
                'end_qty' => Payload::qty($productRecords->sum(fn (InventoryItemMovementDaily $r) => (float) $r->end_quantity)),
            ];
        }

        return [
            'date' => $date,
            'rows' => $rows,
        ];
    }
}
