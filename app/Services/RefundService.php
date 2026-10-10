<?php

namespace App\Services;

use App\Exceptions\RefundException;
use App\Models\Account;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleRefund;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Full-sale refunds only (partial refunds are a later step).
 * Money goes back through the LedgerService, stock is restored, and the sale is marked voided, never deleted.
 */
class RefundService
{
    public function __construct(private LedgerService $ledger)
    {
    }

    /** A cashier asks for a refund. Nothing moves until a manager approves. */
    public function request(Sale $sale, User $user, string $reason): SaleRefund
    {
        return DB::transaction(function () use ($sale, $user, $reason) {
            $locked = Sale::withoutGlobalScopes()->lockForUpdate()->findOrFail($sale->id);

            if ($locked->voided_at !== null) {
                throw RefundException::alreadyRefunded();
            }

            $pending = SaleRefund::withoutGlobalScopes()
                ->where('sale_id', $locked->id)
                ->where('status', 'pending')
                ->exists();

            if ($pending) {
                throw RefundException::refundPending();
            }

            return SaleRefund::create([
                'school_id' => $locked->school_id,
                'sale_id' => $locked->id,
                'requested_by' => $user->id,
                'reason' => $reason,
                'status' => 'pending',
            ]);
        });
    }

    /** A manager approves: money back, stock back, sale voided. All or nothing. */
    public function approve(SaleRefund $refund, User $manager, ?string $note = null): SaleRefund
    {
        return DB::transaction(function () use ($refund, $manager, $note) {
            $locked = SaleRefund::withoutGlobalScopes()->lockForUpdate()->findOrFail($refund->id);

            if ($locked->status !== 'pending') {
                throw RefundException::alreadyDecided();
            }

            $sale = Sale::withoutGlobalScopes()->lockForUpdate()->findOrFail($locked->sale_id);

            if ($sale->voided_at !== null) {
                throw RefundException::alreadyRefunded();
            }

            $account = Account::withoutGlobalScopes()->lockForUpdate()->findOrFail($sale->account_id);
            $sale->load('items');

            // One refund entry per sale, ever: the key makes a second attempt impossible
            $entry = $this->ledger->refund($account, $sale->total, "refund:{$sale->id}", [
                'description' => "Refund of sale #{$sale->id}",
                'reference' => "sale:{$sale->id}",
                'created_by' => $manager->id,
            ]);

            $this->restock($sale, $manager);

            $sale->update(['voided_at' => now()]);

            $locked->update([
                'status' => 'approved',
                'decided_by' => $manager->id,
                'decided_at' => now(),
                'decision_note' => $note,
                'ledger_entry_id' => $entry->id,
            ]);

            return $locked;
        });
    }

    public function reject(SaleRefund $refund, User $manager, ?string $note = null): SaleRefund
    {
        return DB::transaction(function () use ($refund, $manager, $note) {
            $locked = SaleRefund::withoutGlobalScopes()->lockForUpdate()->findOrFail($refund->id);

            if ($locked->status !== 'pending') {
                throw RefundException::alreadyDecided();
            }

            $locked->update([
                'status' => 'rejected',
                'decided_by' => $manager->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);

            return $locked;
        });
    }

    /** A manager refunds a sale directly: the request and the approval happen together, both on record. */
    public function refundNow(Sale $sale, User $manager, string $reason): SaleRefund
    {
        return DB::transaction(fn () => $this->approve(
            $this->request($sale, $manager, $reason),
            $manager,
            'Refunded directly by a manager'
        ));
    }

    private function restock(Sale $sale, User $manager): void
    {
        $products = Product::withoutGlobalScopes()
            ->whereIn('id', $sale->items->pluck('product_id'))
            ->orderBy('id')            // same lock order as selling: no deadlocks
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($sale->items as $item) {
            $product = $products->get($item->product_id);

            if (! $product || ! $product->track_stock) {
                continue;
            }

            $after = $product->stock + $item->quantity;
            $product->update(['stock' => $after]);

            StockMovement::create([
                'school_id' => $sale->school_id,
                'product_id' => $product->id,
                'type' => 'return',
                'quantity' => $item->quantity,
                'stock_after' => $after,
                'reference' => "refund:{$sale->id}",
                'note' => "Refund of sale #{$sale->id}",
                'user_id' => $manager->id,
            ]);
        }
    }
}
