<?php

namespace App\Exceptions;

use RuntimeException;

class SaleException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function emptyCart(): self
    {
        return new self('empty_cart', 'The cart is empty.');
    }

    public static function invalidProduct(): self
    {
        return new self('invalid_product', 'A product in the cart is no longer available. Please start the sale again.');
    }

    public static function outOfStock(string $name, int $available): self
    {
        return new self(
            'out_of_stock',
            $available > 0 ? "{$name}: only {$available} left." : "{$name} is out of stock."
        );
    }
}
