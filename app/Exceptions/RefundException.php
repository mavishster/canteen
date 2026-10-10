<?php

namespace App\Exceptions;

use RuntimeException;

class RefundException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function alreadyRefunded(): self
    {
        return new self('already_refunded', 'This sale has already been refunded.');
    }

    public static function refundPending(): self
    {
        return new self('refund_pending', 'A refund request for this sale is already waiting for approval.');
    }

    public static function alreadyDecided(): self
    {
        return new self('already_decided', 'This refund request has already been decided.');
    }
}
