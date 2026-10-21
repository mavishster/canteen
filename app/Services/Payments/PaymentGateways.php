<?php

namespace App\Services\Payments;

use InvalidArgumentException;

final class PaymentGateways
{
    public static function get(string $name): PaymentGateway
    {
        return match ($name) {
            'fake' => app()->isProduction()
                ? throw new InvalidArgumentException(__('The fake gateway is disabled in production.'))
                : app(FakeGateway::class),
            'aba' => app(AbaPayWayGateway::class),
            default => throw new InvalidArgumentException(__('Unknown payment gateway \':name\'.', ['name' => $name])),
        };
    }

    public static function default(): PaymentGateway
    {
        return self::get((string) config('payments.default'));
    }
}
