<?php

namespace App\Services;

use App\Exceptions\MealException;
use App\Models\Account;
use App\Models\MealConsumption;
use App\Models\MealPlan;
use App\Models\MealSubscription;
use App\Models\MealType;
use App\Models\Student;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MealSubscriptionService
{
    public function __construct(private LedgerService $ledger) {}

    public function purchase(Student $student, int $planId, string $key, ?int $operatorId = null): MealSubscription
    {
        if ($key === '' || mb_strlen($key) > 64) {
            throw new InvalidArgumentException('The purchase key must be between 1 and 64 characters.');
        }

        return DB::transaction(function () use ($student, $planId, $key, $operatorId) {
            $student = Student::withoutGlobalScopes()->lockForUpdate()->findOrFail($student->id);
            $account = Account::withoutGlobalScopes()
                ->lockForUpdate()
                ->where('student_id', $student->id)
                ->firstOrFail();

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

            $plan = MealPlan::withoutGlobalScopes()
                ->where('school_id', $student->school_id)
                ->lockForUpdate()
                ->find($planId);

            if (! $student->is_active) {
                throw MealException::studentInactive();
            }

            if (! $plan || ! $plan->is_active) {
                throw MealException::planUnavailable();
            }

            $mealType = MealType::withoutGlobalScopes()
                ->where('school_id', $student->school_id)
                ->lockForUpdate()
                ->find($plan->meal_type_id);

            if (! $mealType || ! $mealType->is_active) {
                throw MealException::planUnavailable();
            }

            if ($plan->grade !== null && (int) $plan->grade !== (int) $student->grade) {
                throw MealException::planUnavailable();
            }

            $validFrom = CarbonImmutable::now($plan->school->timezone)->startOfDay();
            $expiresOn = $validFrom->addMonthsNoOverflow($plan->duration_months)->subDay();
            $purchaseRef = "meal-subscription:{$key}";

            $entry = $this->ledger->purchase($account, $plan->price, $purchaseRef, [
                'description' => "Meal subscription: {$plan->name}",
                'reference' => $purchaseRef,
                'created_by' => $operatorId,
            ]);

            $subscription = MealSubscription::create([
                'school_id' => $student->school_id,
                'student_id' => $student->id,
                'meal_plan_id' => $plan->id,
                'meal_type_id' => $plan->meal_type_id,
                'ledger_entry_id' => $entry->id,
                'valid_from' => $validFrom->toDateString(),
                'expires_on' => $expiresOn->toDateString(),
                'entitlement_quantity' => $plan->entitlement_quantity,
                'consumed_quantity' => 0,
                'price' => $plan->price,
                'payment_status' => 'paid',
                'status' => 'active',
                'idempotency_key' => $key,
            ]);

            return $subscription->load(['plan', 'mealType', 'ledgerEntry']);
        });
    }

    public function collect(
        Student $student,
        int $mealTypeId,
        string $key,
        ?int $operatorId,
        string $identificationType,
        string $identificationMethod,
        string $identifier,
    ): MealConsumption {
        if ($key === '' || mb_strlen($key) > 64) {
            throw new InvalidArgumentException('The collection key must be between 1 and 64 characters.');
        }

        return DB::transaction(function () use (
            $student,
            $mealTypeId,
            $key,
            $operatorId,
            $identificationType,
            $identificationMethod,
            $identifier,
        ) {
            $student = Student::withoutGlobalScopes()->lockForUpdate()->findOrFail($student->id);
            Account::withoutGlobalScopes()
                ->lockForUpdate()
                ->where('student_id', $student->id)
                ->firstOrFail();

            $school = $student->school;
            $now = CarbonImmutable::now($school->timezone);
            $serviceDate = $now->startOfDay();

            $existing = MealConsumption::withoutGlobalScopes()
                ->where('school_id', $student->school_id)
                ->where('idempotency_key', $key)
                ->first();

            if ($existing) {
                if (
                    (int) $existing->student_id !== (int) $student->id
                    || (int) $existing->meal_type_id !== $mealTypeId
                    || ! $existing->service_date->isSameDay($serviceDate)
                ) {
                    throw MealException::idempotencyConflict();
                }

                return $existing->load(['student', 'mealType', 'subscription']);
            }

            if (! $student->is_active) {
                throw MealException::studentInactive();
            }

            $mealType = MealType::withoutGlobalScopes()
                ->where('school_id', $student->school_id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->find($mealTypeId);

            if (! $mealType) {
                throw MealException::mealUnavailable();
            }

            $weekday = $serviceDate->isoWeekday();
            if ($mealType->available_days !== null && ! in_array($weekday, $mealType->available_days, true)) {
                throw MealException::mealUnavailable();
            }

            if ($mealType->service_starts_at && $mealType->service_ends_at) {
                $localTime = $now->format('H:i');
                if (
                    $localTime < substr($mealType->service_starts_at, 0, 5)
                    || $localTime > substr($mealType->service_ends_at, 0, 5)
                ) {
                    throw MealException::outsideServiceHours();
                }
            }

            $duplicate = MealConsumption::withoutGlobalScopes()
                ->where('student_id', $student->id)
                ->where('meal_type_id', $mealType->id)
                ->whereDate('service_date', $serviceDate->toDateString())
                ->exists();

            if ($duplicate) {
                throw MealException::alreadyCollected();
            }

            $subscription = MealSubscription::withoutGlobalScopes()
                ->where('school_id', $student->school_id)
                ->where('student_id', $student->id)
                ->where('meal_type_id', $mealType->id)
                ->where('status', 'active')
                ->whereDate('valid_from', '<=', $serviceDate->toDateString())
                ->whereDate('expires_on', '>=', $serviceDate->toDateString())
                ->whereColumn('consumed_quantity', '<', 'entitlement_quantity')
                ->orderBy('expires_on')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $subscription) {
                throw MealException::noEntitlement();
            }

            $subscription->increment('consumed_quantity');

            $consumption = MealConsumption::create([
                'school_id' => $student->school_id,
                'student_id' => $student->id,
                'meal_type_id' => $mealType->id,
                'meal_subscription_id' => $subscription->id,
                'operator_id' => $operatorId,
                'service_date' => $serviceDate->toDateString(),
                'identification_type' => $identificationType,
                'identification_method' => $identificationMethod,
                'identifier' => $identifier,
                'idempotency_key' => $key,
            ]);

            return $consumption->load(['student', 'mealType', 'subscription']);
        });
    }
}
