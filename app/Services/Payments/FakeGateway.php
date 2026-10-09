<?php

namespace App\Services\Payments;

use App\Models\Topup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Development and test gateway. Plays both the payment page and the bank. Disabled in production. */
class FakeGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'fake';
    }

    public function createCheckout(Topup $topup): string
    {
        return route('dev.pay', ['ref' => $topup->gateway_ref]);
    }

    public function referenceFromCallback(array $payload, array $headers = []): string
    {
        $ref = (string) ($payload['ref'] ?? '');
        $sig = (string) ($payload['sig'] ?? '');

        if ($ref === '' || ! hash_equals($this->signature($ref), $sig)) {
            throw new InvalidCallbackException('Invalid callback signature.');
        }

        return $ref;
    }

    public function checkTransaction(string $ref): GatewayStatus
    {
        $bank = Cache::get("fake-bank:{$ref}");

        if (! $bank) {
            return new GatewayStatus('pending');
        }

        return new GatewayStatus($bank['state'], $bank['amount'], $bank['currency'], $bank['txn']);
    }

    public function signature(string $ref): string
    {
        return hash_hmac('sha256', $ref, (string) config('app.key'));
    }

    /** Plays the bank: records what really happened to the payment. */
    public function simulate(Topup $topup, string $state, ?int $amount = null, ?string $currency = null): void
    {
        Cache::put("fake-bank:{$topup->gateway_ref}", [
            'state' => $state,
            'amount' => $amount ?? (int) $topup->pay_amount,
            'currency' => $currency ?? $topup->pay_currency,
            'txn' => 'FAKE-' . strtoupper(Str::random(10)),
        ], now()->addDay());
    }
}
