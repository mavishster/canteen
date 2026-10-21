<?php

namespace App\Exceptions;

use App\Support\Money;
use RuntimeException;

class TopUpException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function invalidAmount(): self
    {
        return new self('invalid_amount', __('Enter an amount greater than zero.'));
    }

    public static function unsupportedCurrency(string $currency): self
    {
        return new self('unsupported_currency', __('Payments in :currency are not supported.', ['currency' => $currency]));
    }

    public static function belowMinimum(int $min, string $currency): self
    {
        return new self('below_minimum', __('The minimum top-up is :value.', ['value' => Money::format($min, $currency)]));
    }
}
