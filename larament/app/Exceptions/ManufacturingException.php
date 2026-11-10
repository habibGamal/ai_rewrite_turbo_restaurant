<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class ManufacturingException extends RuntimeException
{
    public function __construct(string $message = 'حدث خطأ في عملية التصنيع', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
