<?php

namespace App\Services\Payments;

/** What the gateway itself says about a payment (never what a callback body claims). */
final class GatewayStatus
{
    /** @param 'paid'|'pending'|'failed' $state */
    public function __construct(
        public readonly string $state,
        public readonly ?int $amount = null,          // minor units of $currency
        public readonly ?string $currency = null,
        public readonly ?string $transactionId = null,
    ) {
    }
}
