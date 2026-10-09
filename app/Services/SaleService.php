<?php

namespace App\Services;

use App\Exceptions\SaleException;
use App\Models\Account;
use App\Models\Product;
use App\Models\Sale;
use App\Models\School;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SaleService
{
    public function __construct(
        private CardService $cards,
        private LedgerService $ledger,
        private RuleService $rules,
        private StockService $stock,
    ) {
    }

    /**
     * One tap = one sale. Prices always come from the database, never from the caller.
     * Safe to retry: the same $key returns the original sale and never charges twice.
     * The balance, the stock and the sale record change together or not at all.
     *
     * @param  array<int, array{product_id:int, quantity:int}>  $items
     */
    public function checkout(int $schoolId, ?int $cashierId, string $uid, array $items, string $key): Sale
    {
        $card = $this->cards->resolve($uid, $schoolId);   // throws CardException with a clear reason
        $account = $card->student->account;

        return DB::transaction(function () use ($schoolId, $cashierId, $items, $key, $account, $card) {
            // Same row lock the ledger uses: two tills on one account wait their turn here
            $locked = Account::withoutGlobalScopes()->lockForUpdate()->findOrFail($account->id);

            $existing = Sale::withoutGlobalScopes()
                ->where('school_id', $schoolId)
                ->where('idempotency_key', $key)
                ->first();

            if ($existing) {
                if ((int) $existing->account_id !== (int) $locked->id) {
                    throw new InvalidArgumentException("Sale key '{$key}' was already used for a different account.");
                }

                return $existing->load(['items', 'student', 'ledgerEntry']);
            }

            $lines = $this->priceLines($schoolId, $items);
            $total = array_sum(array_column($lines, 'line_total'));

            if ($total <= 0) {
                throw SaleException::invalidProduct();
            }

            // Limits, bans and buying hours. Throws RuleException (a SaleException with a reason).
            $this->rules->check(School::findOrFail($schoolId), $card->student, $locked, $lines, $total);

            // Locks the products and deducts stock. Throws out_of_stock before any money moves.
            $this->stock->deductForSale($schoolId, $lines, "sale:{$key}", $cashierId);

            // Throws InsufficientBalanceException: the whole transaction rolls back, stock included
            $entry = $this->ledger->purchase($locked, $total, "sale:{$key}", [
                'description' => 'Canteen purchase',
                'created_by' => $cashierId,
            ]);

            $sale = Sale::create([
                'school_id' => $schoolId,
                'account_id' => $locked->id,
                'student_id' => $card->student_id,
                'cashier_id' => $cashierId,
                'ledger_entry_id' => $entry->id,
                'total' => $total,
                'idempotency_key' => $key,
            ]);

            $sale->items()->createMany($lines);

            return $sale->load(['items', 'student', 'ledgerEntry']);
        });
    }

    /** @return array<int, array{product_id:int, name:string, unit_price:int, quantity:int, line_total:int}> */
    private function priceLines(int $schoolId, array $items): array
    {
        $wanted = [];

        foreach ($items as $item) {
            $id = (int) ($item['product_id'] ?? 0);
            $qty = (int) ($item['quantity'] ?? 0);

            if ($id <= 0 || $qty <= 0 || $qty > 99) {
                throw SaleException::invalidProduct();
            }

            $wanted[$id] = ($wanted[$id] ?? 0) + $qty;   // the same product twice becomes one line
        }

        if (! $wanted) {
            throw SaleException::emptyCart();
        }

        $products = Product::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('is_active', true)
            ->whereIn('id', array_keys($wanted))
            ->get()
            ->keyBy('id');

        $lines = [];

        foreach ($wanted as $id => $qty) {
            $product = $products->get($id);

            if (! $product) {
                throw SaleException::invalidProduct();
            }

            $lines[] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'unit_price' => $product->price,
                'quantity' => $qty,
                'line_total' => $product->price * $qty,
            ];
        }

        return $lines;
    }
}
