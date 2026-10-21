<?php

namespace App\Services;

use App\Exceptions\MealException;
use App\Models\Account;
use App\Models\MealPlan;
use App\Models\MealSubscription;
use App\Models\MealType;
use App\Models\School;
use App\Models\Student;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A meal plan is paid TIME (1, 3, 6 or 12 months), not a number of meals. No refunds.
 * It is paid from the student's wallet through the ledger, and a renewal continues where the current plan ends.
 */
class MealSubscriptionService
{
    /** How far ahead a student may be paid up, counted from today. */
    private const MAX_PREPAID_MONTHS = 12;

    public function __construct(private LedgerService $ledger)
    {
    }

    /**
     * Safe to retry: the same $key returns the original subscription and never charges twice.
     *
     * @throws MealException
     * @throws \App\Exceptions\InsufficientBalanceException
     */
    public function purchase(Student $student, int $planId, string $key, ?int $operatorId = null): MealSubscription
    {
        if ($key === '' || mb_strlen($key) > 64) {
            throw new InvalidArgumentException(__('The purchase key must be between 1 and 64 characters.'));
        }

        return DB::transaction(function () use ($student, $planId, $key, $operatorId) {
            // The same locks the till takes: a plan purchase and a sale for one student wait their turn
            $student = Student::withoutGlobalScopes()->lockForUpdate()->findOrFail($student->id);
            $account = Account::withoutGlobalScopes()->lockForUpdate()->where('student_id', $student->id)->firstOrFail();

            $existing = MealSubscription::withoutGlobalScopes()
                ->where('school_id', $student->school_id)
                ->where('idempotency_key', $key)
                ->first();

            if ($existing) {
                if ((int) $existing->student_id !== (int) $student->id || (int) $existing->meal_plan_id !== $planId) {
                    throw MealException::idempotencyConflict();
                }

                return $existing->load(['plan', 'mealType', 'ledgerEntry']);
            }

            if (! $student->is_active) {
                throw MealException::studentInactive();
            }

            $plan = MealPlan::withoutGlobalScopes()->where('school_id', $student->school_id)->find($planId);
            $mealType = $plan
                ? MealType::withoutGlobalScopes()->where('school_id', $student->school_id)->find($plan->meal_type_id)
                : null;

            if (! $plan || ! $plan->is_active || ! $mealType || ! $mealType->is_active || ! $plan->fitsGrade($student->grade)) {
                throw MealException::planUnavailable();
            }

            $timezone = School::findOrFail($student->school_id)->timezone;
            $today = CarbonImmutable::now($timezone)->startOfDay();

            // Renewing early continues from the end of what is already paid, so no day is paid twice
            $coveredUntil = MealSubscription::withoutGlobalScopes()
                ->where('student_id', $student->id)
                ->where('meal_type_id', $plan->meal_type_id)
                ->max('expires_on');

            $coveredUntil = $coveredUntil ? CarbonImmutable::parse($coveredUntil, $timezone)->startOfDay() : null;

            $start = ($coveredUntil && $coveredUntil->gte($today)) ? $coveredUntil->addDay() : $today;
            $expires = $start->addMonthsNoOverflow($plan->duration_months)->subDay();

            if ($expires->gt($today->addMonthsNoOverflow(self::MAX_PREPAID_MONTHS))) {
                throw MealException::tooFarAhead($coveredUntil ? $coveredUntil->toDateString() : $today->toDateString());
            }

            // Throws InsufficientBalanceException: nothing is recorded
            $entry = $this->ledger->purchase($account, $plan->price, "meal-plan:{$key}", [
                'description' => "Meal plan: {$plan->name}",
                'reference' => "meal-plan:{$key}",
                'created_by' => $operatorId,
            ]);

            $subscription = MealSubscription::create([
                'school_id' => $student->school_id,
                'student_id' => $student->id,
                'meal_plan_id' => $plan->id,
                'meal_type_id' => $plan->meal_type_id,
                'ledger_entry_id' => $entry->id,
                'valid_from' => $start->toDateString(),
                'expires_on' => $expires->toDateString(),
                'price' => $plan->price,
                'idempotency_key' => $key,
            ]);

            return $subscription->load(['plan', 'mealType', 'ledgerEntry']);
        });
    }
}
