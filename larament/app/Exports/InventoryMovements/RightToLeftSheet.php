<?php

namespace App\Exports\InventoryMovements;

use Maatwebsite\Excel\Events\AfterSheet;

/**
 * Right-to-left sheet with a bold heading row.
 */
trait RightToLeftSheet
{
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $event->sheet->getDelegate()->setRightToLeft(true);
                $event->sheet->getDelegate()->getStyle('1:1')->getFont()->setBold(true);
            },
        ];
    }
}
