<?php

namespace App\Services;

use App\Exceptions\CardException;
use App\Exceptions\MealException;
use App\Models\MealConsumption;
use App\Models\MealSubscription;
use App\Models\MealType;
use App\Models\School;
use App\Models\Student;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Serves one meal to one child. One meal per child, per meal type, per school day: enforced by the lock,
 * by this code, and finally by a unique index in the database.
 */
class MealCollectionService
{
    /**
     * @throws MealException      the meal cannot be served (reason in $e->reason)
     * @throws CardException      the student does not belong to this school
     * @throws InvalidArgumentException  bad key or identification
     */
    public function collect(Student $student, int $schoolId, ?int $operatorId, int $mealTypeId, string $identifiedBy, string $key): MealConsumption
    {
        if ($key === '' || mb_strlen($key) > 64) {
            throw new InvalidArgumentException('The key must be between 1 and 64 characters.');
        }

        if (! in_array($identifiedBy, ['card', 'manual'], true)) {
            throw new InvalidArgumentException('Unknown identification method.');
        }

        return DB::transaction(function () use ($student, $schoolId, $operatorId, $mealTypeId, $identifiedBy, $key) {
            // Locking the student serialises every tap for this child, from any reader and for any meal
            $student = Student::withoutGlobalScopes()->lockForUpdate()->findOrFail($student->id);

            if ((int) $student->school_id !== $schoolId) {
                throw CardException::unknownCard();
            }

            $school = School::findOrFail($schoolId);
            $now = CarbonImmutable::now($school->timezone);
            $serviceDate = $now->toDateString();

            // A retried tap returns the original record and never counts twice
            $existing = MealConsumption::withoutGlobalScopes()
                ->where('school_id', $schoolId)
                ->where('idempotency_key', $key)
                ->first();

            if ($existing) {
                if ((int) $existing->student_id !== (int) $student->id || (int) $existing->meal_type_id !== $mealTypeId) {
                    throw MealException::idempotencyConflict();
                }

                return $existing->load(['student', 'mealType', 'subscription']);
            }

            if (! $student->is_active) {
                throw MealException::studentInactive();
            }

            $mealType = MealType::withoutGlobalScopes()
                ->where('school_id', $schoolId)
                ->where('is_active', true)
                ->find($mealTypeId);

            if (! $mealType) {
                throw MealException::mealUnavailable();
            }

            $this->assertServedNow($school, $mealType, $now);

            $done = $this->collectedToday($student, $mealType, $serviceDate, $school);

            if ($done !== null) {
                throw MealException::alreadyCollected($done);
            }

            $subscription = MealSubscription::withoutGlobalScopes()
                ->where('school_id', $schoolId)
                ->where('student_id', $student->id)
                ->where('meal_type_id', $mealType->id)
                ->whereDate('valid_from', '<=', $serviceDate)
                ->whereDate('expires_on', '>=', $serviceDate)
                ->orderBy('expires_on')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $subscription) {
                throw MealException::noSubscription($mealType->name);
            }

            try {
                $consumption = MealConsumption::create([
                    'school_id' => $schoolId,
                    'student_id' => $student->id,
                    'meal_type_id' => $mealType->id,
                    'meal_subscription_id' => $subscription->id,
                    'operator_id' => $operatorId,
                    'service_date' => $serviceDate,
                    'identified_by' => $identifiedBy,
                    'idempotency_key' => $key,
                ]);
            } catch (UniqueConstraintViolationException) {
                // The database caught a second tap that the lock somehow did not stop
                $done = $this->collectedToday($student, $mealType, $serviceDate, $school);

                throw MealException::alreadyCollected($done ?? $now->format('H:i'));
            }

            return $consumption->load(['student', 'mealType', 'subscription']);
        });
    }

    /** Meals served today in the school's day, per active meal type (for the counters on the screen). */
    public function countsToday(School $school): array
    {
        $date = CarbonImmutable::now($school->timezone)->toDateString();

        $counts = MealConsumption::withoutGlobalScopes()
            ->where('school_id', $school->id)
            ->whereDate('service_date', $date)
            ->selectRaw('meal_type_id, count(*) as n')
            ->groupBy('meal_type_id')
            ->pluck('n', 'meal_type_id');

        return MealType::withoutGlobalScopes()
            ->where('school_id', $school->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (MealType $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'count' => (int) ($counts[$t->id] ?? 0),
            ])
            ->values()
            ->all();
    }

    /** The time (school time) of today's collection of this meal, or null if none yet. */
    private function collectedToday(Student $student, MealType $mealType, string $serviceDate, School $school): ?string
    {
        $row = MealConsumption::withoutGlobalScopes()
            ->where('student_id', $student->id)
            ->where('meal_type_id', $mealType->id)
            ->whereDate('service_date', $serviceDate)
            ->first();

        return $row ? $row->created_at->timezone($school->timezone)->format('H:i') : null;
    }

    private function assertServedNow(School $school, MealType $mealType, CarbonImmutable $now): void
    {
        // Days the school is closed: no meals at all, whatever the plan
        $closed = (array) data_get($school->settings, 'calendar.closed_dates', []);

        if (in_array($now->toDateString(), $closed, true)) {
            throw MealException::mealUnavailable();
        }

        $days = $mealType->available_days;

        if (is_array($days) && $days !== [] && ! in_array($now->isoWeekday(), array_map('intval', $days), true)) {
            throw MealException::mealUnavailable();
        }

        if ($mealType->service_starts_at && $mealType->service_ends_at) {
            $from = substr((string) $mealType->service_starts_at, 0, 5);
            $to = substr((string) $mealType->service_ends_at, 0, 5);
            $time = $now->format('H:i');

            if ($time < $from || $time > $to) {
                throw MealException::outsideServiceHours("{$from}–{$to}");
            }
        }
    }
}
