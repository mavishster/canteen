<?php

namespace App\Services;

use App\Exceptions\SaleException;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StockService
{
    /**
     * Deducts stock for a sale. MUST run inside the sale's database transaction:
     * the row locks are what stop two tills selling the last item at the same moment,
     * and a later failure (e.g. not enough balance) rolls the deduction back.
     *
     * @param  array<int, array{product_id:int, quantity:int}>  $lines  one line per product
     */
    public function deductForSale(int $schoolId, array $lines, string $reference, ?int $userId): void
    {
        $products = Product::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->whereIn('id', array_column($lines, 'product_id'))
            ->orderBy('id')            // same lock order everywhere: no deadlocks between tills
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($lines as $line) {
            $product = $products->get($line['product_id']);

            if ($product && $product->track_stock && $product->stock < $line['quantity']) {
                throw SaleException::outOfStock($product->name, max(0, $product->stock));
            }
        }

        foreach ($lines as $line) {
            $product = $products->get($line['product_id']);

            if (! $product || ! $product->track_stock) {
                continue;
            }

            $after = $product->stock - $line['quantity'];
            $product->update(['stock' => $after]);

            StockMovement::create([
                'school_id' => $schoolId,
                'product_id' => $product->id,
                'type' => 'sale',
                'quantity' => -$line['quantity'],
                'stock_after' => $after,
                'reference' => $reference,
                'user_id' => $userId,
            ]);
        }
    }

    /** A delivery arrived: add to the stock. */
    public function receive(Product $product, int $quantity, ?int $userId = null, ?string $note = null): StockMovement
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('The quantity must be greater than zero.');
        }

        return $this->move($product, 'received', fn (int $current) => $current + $quantity, $userId, $note);
    }

    /** A physical count: set the stock to the counted number. */
    public function count(Product $product, int $counted, ?int $userId = null, ?string $note = null): StockMovement
    {
        if ($counted < 0) {
            throw new InvalidArgumentException('The counted stock cannot be negative.');
        }

        return $this->move($product, 'adjustment', fn () => $counted, $userId, $note);
    }

    private function move(Product $product, string $type, callable $newStock, ?int $userId, ?string $note): StockMovement
    {
        return DB::transaction(function () use ($product, $type, $newStock, $userId, $note) {
            $locked = Product::withoutGlobalScopes()->lockForUpdate()->findOrFail($product->id);

            if (! $locked->track_stock) {
                throw new InvalidArgumentException('Stock is not tracked for this product. Turn on stock tracking first.');
            }

            $after = $newStock($locked->stock);
            $delta = $after - $locked->stock;

            $locked->update(['stock' => $after]);

            return StockMovement::create([
                'school_id' => $locked->school_id,
                'product_id' => $locked->id,
                'type' => $type,
                'quantity' => $delta,
                'stock_after' => $after,
                'note' => $note,
                'user_id' => $userId,
            ]);
        });
    }
}
