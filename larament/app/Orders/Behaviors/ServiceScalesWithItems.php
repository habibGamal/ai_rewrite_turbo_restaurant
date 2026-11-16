<?php

namespace App\Orders\Behaviors;

/**
 * Marks an order type whose service charge is a percentage of the items
 * (dine-in). Returning items then refunds their share of the service too.
 * A flat service such as a delivery fee is not refunded with the items.
 */
interface ServiceScalesWithItems {}
