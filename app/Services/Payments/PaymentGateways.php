<?php

namespace App\Services\Payments;

use InvalidArgumentException;

final class PaymentGateways
{
    public static function get(string $name): PaymentGateway
    {
        return match ($name) {
            'fake' => app()->isProduction()
                ? throw new InvalidArgumentException('The fake gateway is disabled in production.')
                : app(FakeGateway::class),
            'aba' => app(AbaPayWayGateway::class),
            default => throw new InvalidArgumentException("Unknown payment gateway '{$name}'."),
        };
    }

    public static function default(): PaymentGateway
    {
        return self::get((string) config('payments.default'));
    }
}
