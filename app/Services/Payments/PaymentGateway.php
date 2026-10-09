<?php

namespace App\Services\Payments;

use App\Models\Topup;

interface PaymentGateway
{
    /** Short stable name stored on each top-up: 'fake', 'aba'. */
    public function name(): string;

    /** Registers the payment with the gateway and returns the URL to send the payer to. */
    public function createCheckout(Topup $topup): string;

    /**
     * Verifies the callback (signature) and returns OUR reference from it.
     * Throws InvalidCallbackException if it cannot be trusted. Amounts in the body are never used.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headers
     */
    public function referenceFromCallback(array $payload, array $headers = []): string;

    /** Server-to-server question to the gateway: what really happened to this payment? */
    public function checkTransaction(string $ref): GatewayStatus;
}
