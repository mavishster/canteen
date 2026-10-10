<?php

namespace App\Services;

use App\Exceptions\CardException;
use App\Exceptions\MealException;
use App\Models\MealConsumption;
use App\Models\Student;
use Illuminate\Support\Facades\Log;

class MealDistributionService
{
    public function __construct(
        private CardService $cards,
        private MealSubscriptionService $subscriptions,
    ) {}

    public function collect(
        int $schoolId,
        ?int $operatorId,
        string $identifierType,
        string $identificationMethod,
        string $identifier,
        int $mealTypeId,
        string $key,
    ): MealConsumption {
        $student = null;

        try {
            if ($identificationMethod === 'manual' && ! config('canteen.manual_meal_identification_enabled')) {
                throw MealException::manualIdentificationDisabled();
            }

            if (
                $identifierType === 'rfid_card_id'
                && in_array($identificationMethod, ['rfid', 'manual'], true)
            ) {
                $student = $this->cards->resolve($identifier, $schoolId)->student;
            } elseif ($identifierType === 'student_id' && $identificationMethod === 'manual') {
                $student = Student::withoutGlobalScopes()
                    ->where('school_id', $schoolId)
                    ->where('student_code', trim($identifier))
                    ->first();

                if (! $student) {
                    throw MealException::studentNotFound();
                }
            } else {
                throw MealException::identifierInvalid();
            }

            if (! $student->is_active) {
                throw MealException::studentInactive();
            }

            $consumption = $this->subscriptions->collect(
                $student,
                $mealTypeId,
                $key,
                $operatorId,
                $identifierType,
                $identificationMethod,
                $identifier,
            );
        } catch (CardException|MealException $exception) {
            Log::notice('Meal distribution rejected.', [
                'operator_id' => $operatorId,
                'school_id' => $schoolId,
                'student_id' => $student?->id,
                'identification_type' => $identifierType,
                'identification_method' => $identificationMethod,
                'meal_type_id' => $mealTypeId,
                'reason' => $exception->reason,
            ]);

            throw $exception;
        }

        Log::info('Meal distributed.', [
            'operator_id' => $operatorId,
            'school_id' => $schoolId,
            'student_id' => $consumption->student_id,
            'identification_type' => $consumption->identification_type,
            'identification_method' => $consumption->identification_method,
            'meal_type_id' => $consumption->meal_type_id,
            'consumption_id' => $consumption->id,
        ]);

        return $consumption;
    }
}
