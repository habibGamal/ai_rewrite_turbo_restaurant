<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class StockOperationException extends RuntimeException
{
    public function __construct(string $message = 'فشل في تحديث المخزون', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
