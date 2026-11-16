<?php

namespace App\Modules\WebOrders\Exceptions;

use App\Models\Order;
use Exception;

/**
 * The website sent an order number the POS already holds, but the saved order
 * is not the same order (different key, customer, total or items). The POS
 * neither saves it again nor claims to have received it.
 */
final class WebOrderConflictException extends Exception
{
    /**
     * @param  list<string>  $mismatches
     */
    public function __construct(
        public readonly Order $existing,
        public readonly array $mismatches,
    ) {
        parent::__construct('رقم الطلب مستخدم بالفعل لطلب مختلف: '.implode('، ', $mismatches));
    }
}
