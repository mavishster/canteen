<?php

namespace App\Http\Controllers\ParentApi;

use App\Exceptions\CardException;
use App\Exceptions\RuleException;
use App\Models\Card;
use App\Models\Category;
use App\Models\LedgerEntry;
use App\Models\Product;
use App\Models\Sale;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentBan;
use App\Services\CardService;
use App\Services\RuleService;
use App\Support\Money;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ChildController extends ParentBaseController
{
    public function index(Request $request)
    {
        $school = $this->school($request);

        $students = Student::with([
                'account',
                'cards' => fn ($q) => $q->whereIn('status', [Card::ACTIVE, Card::BLOCKED]),
            ])
            ->whereIn('id', $this->linkedIds($request))
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $students->map(fn (Student $s) => $this->present($s, $school))->values(),
        ]);
    }

    public function transactions(Request $request, int $student)
    {
        $child = $this->child($request, $student);
        $school = $this->school($request);

        $entries = LedgerEntry::where('account_id', $child->account->id)
            ->latest('id')
            ->paginate(20);

        // Purchases come with what was bought
        $sales = Sale::with('items')
            ->whereIn('ledger_entry_id', $entries->pluck('id'))
            ->get()
            ->keyBy('ledger_entry_id');

        $entries->through(function (LedgerEntry $e) use ($sales, $school) {
            $sale = $sales->get($e->id);

            return [
                'id' => $e->id,
                'type' => $e->type->value,
                'amount' => $e->amount,
                'amount_formatted' => Money::format($e->amount, $school->currency),
                'balance_after' => $e->balance_after,
                'balance_after_formatted' => Money::format($e->balance_after, $school->currency),
                'description' => $e->description,
                'created_at' => $e->created_at->toIso8601String(),
                'created_at_local' => $e->created_at->timezone($school->timezone)->format('Y-m-d H:i'),
                'items' => $sale ? $sale->items->map(fn ($i) => [
                    'name' => $i->name,
                    'quantity' => $i->quantity,
                    'line_total' => $i->line_total,
                ])->values() : null,
            ];
        });

        return response()->json($entries);
    }

    /** Limits, bans, and everything a parent can choose to ban. */
    public function rules(Request $request, int $student)
    {
        $child = $this->child($request, $student);
        $school = $this->school($request);

        $bans = StudentBan::with(['product:id,name', 'category:id,name'])
            ->where('student_id', $child->id)
            ->get()
            ->map(fn (StudentBan $b) => [
                'id' => $b->id,
                'type' => $b->category_id ? 'category' : 'product',
                'name' => $b->category?->name ?? $b->product?->name,
            ])
            ->values();

        return response()->json([
            'limits' => $this->limits($child, $school),
            'bans' => $bans,
            'catalog' => [
                'categories' => Category::where('school_id', $child->school_id)->orderBy('name')->get(['id', 'name']),
                'products' => Product::with('category:id,name')
                    ->where('school_id', $child->school_id)
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get()
                    ->map(fn (Product $p) => ['id' => $p->id, 'name' => $p->name, 'category' => $p->category?->name])
                    ->values(),
            ],
        ]);
    }

    /** Send both values. An empty value removes that limit. */
    public function setLimits(Request $request, int $student, RuleService $rules)
    {
        $child = $this->child($request, $student);
        $school = $this->school($request);

        $data = $request->validate([
            'daily_limit' => ['nullable', 'numeric', 'min:0.01', 'max:100000000'],
            'weekly_limit' => ['nullable', 'numeric', 'min:0.01', 'max:100000000'],
        ]);

        $daily = filled($data['daily_limit'] ?? null) ? Money::toMinor($data['daily_limit'], $school->currency) : null;
        $weekly = filled($data['weekly_limit'] ?? null) ? Money::toMinor($data['weekly_limit'], $school->currency) : null;

        try {
            $rules->setLimits($child, $daily, $weekly);
        } catch (RuleException | InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'limits' => $this->limits($child->fresh('account'), $school)]);
    }

    public function addBan(Request $request, int $student, RuleService $rules)
    {
        $child = $this->child($request, $student);

        $data = $request->validate([
            'type' => ['required', 'in:product,category'],
            'id' => ['required', 'integer'],
        ]);

        try {
            $ban = $data['type'] === 'product'
                ? $rules->banProduct($child, Product::where('school_id', $child->school_id)->findOrFail($data['id']))
                : $rules->banCategory($child, Category::where('school_id', $child->school_id)->findOrFail($data['id']));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'id' => $ban->id], 201);
    }

    public function removeBan(Request $request, int $student, int $ban, RuleService $rules)
    {
        $child = $this->child($request, $student);

        $rules->unban(StudentBan::where('student_id', $child->id)->findOrFail($ban));

        return response()->json(['ok' => true]);
    }

    public function blockCard(Request $request, int $student, CardService $cards)
    {
        return $this->changeCard($request, $student, $cards, 'block');
    }

    public function unblockCard(Request $request, int $student, CardService $cards)
    {
        return $this->changeCard($request, $student, $cards, 'unblock');
    }

    private function changeCard(Request $request, int $student, CardService $cards, string $action)
    {
        $child = $this->child($request, $student);
        $card = $cards->currentCard($child);

        if (! $card) {
            return response()->json(['message' => 'This student has no card yet.'], 422);
        }

        try {
            $card = $action === 'block' ? $cards->block($card) : $cards->unblock($card);
        } catch (CardException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => $card->status]);
    }

    /** @return array<string, mixed> */
    private function present(Student $s, School $school): array
    {
        $card = $s->cards->first();

        return [
            'id' => $s->id,
            'name' => $s->name,
            'student_code' => $s->student_code,
            'grade' => $s->grade,
            'currency' => $school->currency,
            'balance' => $s->account->balance,
            'balance_formatted' => Money::format($s->account->balance, $school->currency),
            'card' => $card ? ['status' => $card->status] : null,   // the card number itself is never sent
            'limits' => $this->limits($s, $school),
        ];
    }

    /** @return array<string, array{personal: int|null, school_max: int|null}> */
    private function limits(Student $s, School $school): array
    {
        $account = $s->account;

        return [
            'daily' => ['personal' => $account->daily_limit, 'school_max' => $school->capForGrade('daily', $s->grade)],
            'weekly' => ['personal' => $account->weekly_limit, 'school_max' => $school->capForGrade('weekly', $s->grade)],
        ];
    }
}
