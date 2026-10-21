<?php

namespace App\Http\Controllers\ParentApi;

use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\MealException;
use App\Models\MealPlan;
use App\Models\MealSubscription;
use App\Models\MealType;
use App\Services\MealSubscriptionService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use InvalidArgumentException;

class MealController extends ParentBaseController
{
    /** For each meal: until when this child is paid, and the plans the parent can buy. */
    public function index(Request $request, int $student)
    {
        $child = $this->child($request, $student);
        $school = $this->school($request);
        $today = CarbonImmutable::now($school->timezone)->toDateString();

        $plans = MealPlan::where('is_active', true)->orderBy('duration_months')->get()
            ->filter(fn (MealPlan $p) => $p->fitsGrade($child->grade))
            ->groupBy('meal_type_id');

        $meals = MealType::where('is_active', true)->orderBy('name')->get()
            ->map(function (MealType $type) use ($child, $school, $plans, $today) {
                $paidUntil = MealSubscription::where('student_id', $child->id)->where('meal_type_id', $type->id)->max('expires_on');
                $activeNow = MealSubscription::where('student_id', $child->id)->where('meal_type_id', $type->id)
                    ->whereDate('valid_from', '<=', $today)->whereDate('expires_on', '>=', $today)->exists();

                return [
                    'meal_type' => ['id' => $type->id, 'name' => $type->name],
                    'paid_until' => $paidUntil ? substr((string) $paidUntil, 0, 10) : null,
                    'active_now' => $activeNow,
                    'plans' => ($plans->get($type->id) ?? collect())->map(fn (MealPlan $p) => [
                        'id' => $p->id,
                        'name' => $p->name,
                        'duration_months' => $p->duration_months,
                        'price' => $p->price,
                        'price_formatted' => Money::format($p->price, $school->currency),
                    ])->values(),
                ];
            })
            ->filter(fn (array $meal) => $meal['plans']->isNotEmpty() || $meal['paid_until'] !== null)
            ->values();

        return response()->json(['meals' => $meals]);
    }

    /** Buys a plan from the child's wallet. `key` makes a retry safe: the same key never charges twice. */
    public function subscribe(Request $request, int $student, MealSubscriptionService $meals)
    {
        $child = $this->child($request, $student);
        $school = $this->school($request);

        $data = $request->validate([
            'plan_id' => ['required', 'integer'],
            'key' => ['required', 'string', 'max:64'],
        ]);

        try {
            $subscription = $meals->purchase($child, (int) $data['plan_id'], 'parent-' . $data['key'], $request->user()->id);
        } catch (MealException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 422);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => 'invalid_key'], 422);
        } catch (InsufficientBalanceException $e) {
            return response()->json([
                'message' => __('Not enough balance. Balance: :value, needed: :value2.', ['value' => Money::format($e->balance, $school->currency), 'value2' => Money::format($e->required, $school->currency)]),
                'reason' => 'insufficient_balance',
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'plan' => $subscription->plan->name,
            'meal_type' => $subscription->mealType->name,
            'valid_from' => $subscription->valid_from->toDateString(),
            'expires_on' => $subscription->expires_on->toDateString(),
            'balance' => $subscription->ledgerEntry->balance_after,
            'balance_formatted' => Money::format($subscription->ledgerEntry->balance_after, $school->currency),
        ], 201);
    }
}
