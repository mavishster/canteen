<?php

namespace App\Exceptions;

use RuntimeException;

class MealException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function planUnavailable(): self
    {
        return new self('plan_unavailable', 'This meal plan is no longer available for this student.');
    }

    public static function mealUnavailable(): self
    {
        return new self('meal_unavailable', 'This meal is not available on this day.');
    }

    public static function outsideServiceHours(): self
    {
        return new self('outside_service_hours', 'This meal is outside its collection hours.');
    }

    public static function noEntitlement(): self
    {
        return new self('no_entitlement', 'No valid meal entitlement is available.');
    }

    public static function alreadyCollected(): self
    {
        return new self('already_collected', 'This student has already collected this meal today.');
    }

    public static function identifierInvalid(): self
    {
        return new self('invalid_identifier', 'Choose a valid student identification type and identifier.');
    }

    public static function studentNotFound(): self
    {
        return new self('student_not_found', 'No student matches that identifier.');
    }

    public static function studentInactive(): self
    {
        return new self('student_inactive', 'This student is inactive.');
    }

    public static function manualIdentificationDisabled(): self
    {
        return new self('manual_identification_disabled', 'Manual student identification is disabled.');
    }

    public static function idempotencyConflict(): self
    {
        return new self('idempotency_conflict', 'This request key was already used for a different operation.');
    }
}
