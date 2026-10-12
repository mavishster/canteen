<?php

namespace App\Exceptions;

use RuntimeException;

class SisLoginException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function invalidToken(): self
    {
        return new self('invalid_token', 'Could not verify your login with the school system. Please sign in again.', 422);
    }

    /** Not a parent, or not active: one message, so nobody can probe which accounts exist. */
    public static function notAllowed(): self
    {
        return new self('not_allowed', 'This account cannot use the canteen app.', 403);
    }

    public static function schoolUnknown(): self
    {
        return new self('school_unknown', 'Your school is not set up for the canteen app yet.', 403);
    }

    public static function unavailable(): self
    {
        return new self('sis_unavailable', 'The school system cannot be reached right now. Please try again in a few minutes.', 503);
    }

    public static function disabled(): self
    {
        return new self('account_disabled', 'This account is no longer active. Please sign in again.', 401);
    }
}
