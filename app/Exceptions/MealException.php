<?php

namespace App\Exceptions;

use RuntimeException;

class MealException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    // ---- buying a plan
    public static function studentInactive(): self
    {
        return new self('student_inactive', 'This student account is inactive.');
    }

    public static function planUnavailable(): self
    {
        return new self('plan_unavailable', 'This meal plan is not available for this student.');
    }

    public static function idempotencyConflict(): self
    {
        return new self('idempotency_conflict', 'This purchase was already used for something else. Please start again.');
    }

    public static function tooFarAhead(string $paidUntil): self
    {
        return new self('too_far_ahead', "This meal is already paid until {$paidUntil}. You can renew closer to that date.");
    }

    // ---- the meal screen (next step)
    public static function mealUnavailable(): self
    {
        return new self('meal_unavailable', 'This meal is not served today.');
    }

    public static function outsideServiceHours(string $window): self
    {
        return new self('outside_hours', "This meal is not being served now ({$window}).");
    }

    public static function noSubscription(string $meal): self
    {
        return new self('no_subscription', "No active {$meal} plan.");
    }

    public static function alreadyCollected(string $at): self
    {
        return new self('already_collected', "Already collected today at {$at}.");
    }
}
