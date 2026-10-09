<?php

namespace App\Services\Payments;

use App\Models\Topup;
use LogicException;

/**
 * ABA PayWay driver: NOT IMPLEMENTED YET. It needs the sandbox credentials and the API documentation.
 *
 * What each method must do (from the requirements document):
 *  - createCheckout():  build the create-transaction request, sign it with HMAC-SHA512 using the
 *                       ABA-issued merchant key, and return ABA's hosted payment page URL.
 *                       Card numbers are entered only on ABA's page and are never stored by us.
 *  - referenceFromCallback(): verify ABA's callback, return our reference. Never trust amounts in it.
 *  - checkTransaction(): call ABA's Check-Transaction API and map the result to a GatewayStatus.
 *
 * TopUpService already handles everything around this: idempotency, amount checks, crediting the ledger.
 */
class AbaPayWayGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'aba';
    }

    public function createCheckout(Topup $topup): string
    {
        throw $this->notReady();
    }

    public function referenceFromCallback(array $payload, array $headers = []): string
    {
        throw $this->notReady();
    }

    public function checkTransaction(string $ref): GatewayStatus
    {
        throw $this->notReady();
    }

    private function notReady(): LogicException
    {
        return new LogicException('ABA PayWay is not implemented yet: it needs sandbox credentials and the API documentation.');
    }
}
