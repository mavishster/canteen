<?php

namespace App\Services;

use App\Exceptions\TopUpException;
use App\Models\Account;
use App\Models\School;
use App\Models\Student;
use App\Models\Topup;
use App\Services\Payments\GatewayStatus;
use App\Services\Payments\PaymentGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class TopUpService
{
    public function __construct(private LedgerService $ledger)
    {
    }

    /**
     * Step 1: create a pending top-up and get the gateway's payment page URL.
     * Nothing is credited here. Money only moves in settle(), after the gateway confirms it.
     *
     * @param  int  $payAmount  minor units of $payCurrency (what the payer will pay)
     */
    public function start(Student $student, int $payAmount, string $payCurrency, PaymentGateway $gateway, ?int $userId = null): Topup
    {
        $school = School::findOrFail($student->school_id);
        $payCurrency = strtoupper($payCurrency);

        if ($payAmount <= 0) {
            throw TopUpException::invalidAmount();
        }

        [$credit, $rate] = $this->convert($payAmount, $payCurrency, $school);

        if ($credit < $school->minTopUp()) {
            throw TopUpException::belowMinimum($school->minTopUp(), $school->currency);
        }

        $account = Account::withoutGlobalScopes()->where('student_id', $student->id)->firstOrFail();

        $topup = Topup::create([
            'school_id' => $school->id,
            'account_id' => $account->id,
            'student_id' => $student->id,
            'initiated_by' => $userId,
            'amount' => $credit,
            'pay_currency' => $payCurrency,
            'pay_amount' => $payAmount,
            'exchange_rate' => $rate,
            'gateway' => $gateway->name(),
            'gateway_ref' => 'T' . strtoupper(Str::random(15)),
            'status' => 'pending',
        ]);

        try {
            $topup->update(['checkout_url' => $gateway->createCheckout($topup)]);
        } catch (Throwable $e) {
            $topup->update(['status' => 'failed', 'failure_reason' => 'gateway_error']);

            throw $e;
        }

        return $topup;
    }

    /**
     * Step 2: the gateway called us. The callback only tells us WHICH payment to look at;
     * the real result comes from asking the gateway directly.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headers
     */
    public function handleCallback(PaymentGateway $gateway, array $payload, array $headers = []): Topup
    {
        $ref = $gateway->referenceFromCallback($payload, $headers);   // verifies the signature
        $status = $gateway->checkTransaction($ref);                   // server-to-server

        return $this->settle($ref, $gateway->name(), $status);
    }

    /**
     * Applies a verified gateway status. Safe to call any number of times for the same payment:
     * the row lock plus the ledger's unique key mean it can never credit twice.
     */
    public function settle(string $ref, string $gatewayName, GatewayStatus $status): Topup
    {
        return DB::transaction(function () use ($ref, $gatewayName, $status) {
            $topup = Topup::withoutGlobalScopes()
                ->lockForUpdate()
                ->where('gateway_ref', $ref)
                ->where('gateway', $gatewayName)
                ->firstOrFail();

            // 'expired' can still be paid late: the money may really have been taken
            if (! in_array($topup->status, ['pending', 'expired'], true)) {
                return $topup;
            }

            if ($status->state === 'pending') {
                return $topup;
            }

            if ($status->state === 'failed') {
                if ($topup->status === 'pending') {
                    $topup->update(['status' => 'failed', 'failure_reason' => 'declined']);
                }

                return $topup;
            }

            // Paid: the bank must report exactly what we asked for, otherwise a person looks at it
            if ($status->amount !== (int) $topup->pay_amount || $status->currency !== $topup->pay_currency) {
                $topup->update(['status' => 'review', 'failure_reason' => 'amount_mismatch']);

                return $topup;
            }

            $account = Account::withoutGlobalScopes()->findOrFail($topup->account_id);

            $meta = [
                'description' => 'Top-up',
                'reference' => $status->transactionId ?? $topup->gateway_ref,
                'created_by' => $topup->initiated_by,
            ];

            if ($topup->exchange_rate !== null) {
                $meta += [
                    'source_currency' => $topup->pay_currency,
                    'source_amount' => $topup->pay_amount,
                    'exchange_rate' => $topup->exchange_rate,
                ];
            }

            $entry = $this->ledger->topUp($account, $topup->amount, "topup:{$topup->gateway_ref}", $meta);

            $topup->update([
                'status' => 'paid',
                'paid_at' => now(),
                'gateway_txn_id' => $status->transactionId,
                'ledger_entry_id' => $entry->id,
                'failure_reason' => null,
            ]);

            return $topup;
        });
    }

    /** @return array{0:int, 1:int|null} credited minor units in the school's currency, and the rate used */
    private function convert(int $payAmount, string $payCurrency, School $school): array
    {
        $currency = $school->currency;

        foreach ([$payCurrency, $currency] as $c) {
            if (! in_array($c, ['USD', 'KHR'], true)) {
                throw TopUpException::unsupportedCurrency($c);
            }
        }

        if ($payCurrency === $currency) {
            return [$payAmount, null];
        }

        $rate = $school->khrPerUsd();

        return $currency === 'USD'
            ? [intdiv($payAmount * 100, $rate), $rate]           // riel -> cents, rounded down
            : [(int) round($payAmount * $rate / 100), $rate];     // cents -> riel
    }
}
