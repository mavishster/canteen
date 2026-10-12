<?php

namespace App\Http\Controllers;

use App\Exceptions\CardException;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\SaleException;
use App\Models\Product;
use App\Models\School;
use App\Services\SaleService;
use App\Services\StudentLookup;
use App\Support\Money;
use Illuminate\Http\Request;
use InvalidArgumentException;

class TillController extends Controller
{
    public function index(Request $request)
    {
        $schoolId = $request->user()->school_id;
        $currency = $schoolId ? School::findOrFail($schoolId)->currency : 'USD';

        $products = $schoolId
            ? Product::with('category:id,name')
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(fn (Product $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'price' => $p->price,
                    'price_formatted' => Money::format($p->price, $currency),
                    'category' => $p->category?->name ?? 'Other',
                    'track_stock' => $p->track_stock,
                    'stock' => $p->stock,
                ])
                ->values()
            : collect();

        return view('till.index', compact('products', 'currency'));
    }

    /**
     * The box holds a card number (a tap) or a student number (typed). A student number is never charged
     * straight away: the first answer is {confirm: true, student}, and the cashier's confirmation repeats
     * the request with confirm_student = that student's number.
     */
    public function checkout(Request $request, SaleService $sales, StudentLookup $lookup)
    {
        $data = $request->validate([
            'uid' => ['required', 'string', 'max:40'],
            'key' => ['required', 'string', 'max:64'],
            'confirm_student' => ['nullable', 'string', 'max:50'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        $user = $request->user();

        if (! $user->school_id) {
            return $this->reject('Log in as a school user to take payments.', 'no_school');
        }

        $currency = School::findOrFail($user->school_id)->currency;
        $input = trim($data['uid']);

        try {
            $found = $lookup->identify($user->school_id, $input);

            if ($found['type'] === 'none' && ! $found['looks_like_card']) {
                return $this->reject('Card or student number not found.', 'unknown_identifier');
            }

            if ($found['type'] === 'student') {
                $student = $found['student'];

                // Refuse what could never succeed BEFORE asking the cashier to confirm
                $sales->assertManualAllowed($user->school_id, $student);

                if (trim((string) ($data['confirm_student'] ?? '')) !== (string) $student->student_code) {
                    return response()->json([
                        'confirm' => true,
                        'student' => [
                            'name' => $student->name,
                            'grade' => $student->grade,
                            'code' => $student->student_code,
                        ],
                    ]);
                }

                $sale = $sales->checkoutForStudent($user->school_id, $user->id, $student, $data['items'], $data['key']);
            } else {
                // A card tap, or something shaped like a card that this school does not know (answers "Card not recognised")
                $sale = $sales->checkout($user->school_id, $user->id, $input, $data['items'], $data['key']);
            }
        } catch (CardException | SaleException $e) {
            return $this->reject($e->getMessage(), $e->reason);
        } catch (InsufficientBalanceException $e) {
            return $this->reject(
                'Not enough balance. Balance: ' . Money::format($e->balance, $currency)
                . ', needed: ' . Money::format($e->required, $currency) . '.',
                'insufficient_balance'
            );
        } catch (InvalidArgumentException) {
            return $this->reject('This payment was already used for a different sale. Please start a new sale.', 'key_conflict');
        }

        $balance = $sale->ledgerEntry->balance_after;

        return response()->json([
            'ok' => true,
            'sale_id' => $sale->id,
            'identified_by' => $sale->identified_by,
            'student' => ['name' => $sale->student->name, 'code' => $sale->student->student_code],
            'total' => $sale->total,
            'total_formatted' => Money::format($sale->total, $currency),
            'balance' => $balance,
            'balance_formatted' => Money::format($balance, $currency),
            'items' => $sale->items->map(fn ($i) => [
                'name' => $i->name,
                'quantity' => $i->quantity,
                'line_total_formatted' => Money::format($i->line_total, $currency),
            ])->values(),
            // Fresh stock for the tracked products just sold, so the till screen stays accurate
            'stock' => Product::whereIn('id', $sale->items->pluck('product_id'))
                ->where('track_stock', true)
                ->pluck('stock', 'id'),
        ]);
    }

    private function reject(string $message, string $reason)
    {
        return response()->json(['message' => $message, 'reason' => $reason], 422);
    }
}
