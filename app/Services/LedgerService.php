<?php

namespace App\Services;

use App\Enums\LedgerType;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Account;
use App\Models\LedgerEntry;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LedgerService
{
    public function topUp(Account $account, int $amount, string $key, array $meta = []): LedgerEntry
    {
        return $this->post($account, LedgerType::TopUp, $this->positive($amount), $key, $meta);
    }

    public function purchase(Account $account, int $amount, string $key, array $meta = []): LedgerEntry
    {
        return $this->post($account, LedgerType::Purchase, -$this->positive($amount), $key, $meta);
    }

    public function refund(Account $account, int $amount, string $key, array $meta = []): LedgerEntry
    {
        return $this->post($account, LedgerType::Refund, $this->positive($amount), $key, $meta);
    }

    public function adjust(Account $account, int $signedAmount, string $key, array $meta = []): LedgerEntry
    {
        return $this->post($account, LedgerType::Adjustment, $signedAmount, $key, $meta);
    }

    public function post(Account $account, LedgerType $type, int $amount, string $key, array $meta = []): LedgerEntry
    {
        if ($amount === 0) {
            throw new InvalidArgumentException('Amount cannot be zero.');
        }

        $entry = DB::transaction(function () use ($account, $type, $amount, $key, $meta) {
            // Lock this account's row: a second till waits here until we commit
            $locked = Account::withoutGlobalScopes()->lockForUpdate()->findOrFail($account->id);

            // Retried request? Return the original result instead of charging again.
            $existing = LedgerEntry::withoutGlobalScopes()
                ->where('school_id', $locked->school_id)
                ->where('idempotency_key', $key)
                ->first();

            if ($existing) {
                if (
                    $existing->account_id !== $locked->id
                    || $existing->amount !== $amount
                    || $existing->type !== $type
                ) {
                    throw new InvalidArgumentException("Idempotency key '{$key}' was already used for a different transaction.");
                }

                return $existing;
            }

            $newBalance = $locked->balance + $amount;

            if ($newBalance < 0) {
                throw new InsufficientBalanceException($locked->balance, abs($amount));
            }

            $entry = LedgerEntry::create([
                'school_id' => $locked->school_id,
                'account_id' => $locked->id,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $newBalance,
                'idempotency_key' => $key,
            ] + Arr::only($meta, [
                            'description',
                            'reference',
                            'created_by',
                            'source_currency',
                            'source_amount',
                            'exchange_rate',
                        ]));

            $locked->update(['balance' => $newBalance]);

            return $entry;
        });

        $account->refresh();

        return $entry;
    }

    /** True when the stored balance equals the sum of all ledger entries. */
    public function isConsistent(Account $account): bool
    {
        $sum = (int) LedgerEntry::withoutGlobalScopes()->where('account_id', $account->id)->sum('amount');
        $balance = (int) Account::withoutGlobalScopes()->whereKey($account->id)->value('balance');

        return $sum === $balance;
    }

    private function positive(int $amount): int
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }

        return $amount;
    }
}