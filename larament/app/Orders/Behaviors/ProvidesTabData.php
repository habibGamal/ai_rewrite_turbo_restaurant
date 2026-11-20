<?php

namespace App\Orders\Behaviors;

use App\Models\OrderTypeRow;

/**
 * A behaviour that sends extra data with its cashier tab, such as the dine-in
 * floor layout and which tables are taken. Lets a module feed its own tab
 * without the cashier controller knowing about it.
 */
interface ProvidesTabData
{
    /**
     * Keys merged into the tab payload sent to the cashier screen.
     *
     * @return array<string, mixed>
     */
    public function tabData(OrderTypeRow $type): array;
}
