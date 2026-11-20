<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the business type is changed after the install has taken its
 * first order. The type is fixed from that point; modules still toggle.
 */
class BusinessTypeLockedException extends RuntimeException
{
    public function __construct(string $message = 'لا يمكن تغيير نوع النشاط بعد تسجيل أول طلب.')
    {
        parent::__construct($message);
    }
}
