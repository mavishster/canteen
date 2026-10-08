<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientBalanceException extends RuntimeException
{
    public function __construct(public readonly int $balance, public readonly int $required)
    {
        parent::__construct("Insufficient balance: has {$balance}, needs {$required}.");
    }
}