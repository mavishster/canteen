<?php

namespace App\Exceptions;

use RuntimeException;

class CardException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function invalidUid(): self
    {
        return new self('invalid_uid', __('Card number is not valid.'));
    }
    public static function uidInUse(string $uid): self
    {
        return new self('uid_in_use', __('Card :uid is already registered.', ['uid' => $uid]));
    }
    public static function studentHasCard(): self
    {
        return new self('student_has_card', __('This student already has a card. Use replace instead.'));
    }
    public static function unknownCard(): self
    {
        return new self('unknown_card', __('Card not recognised.'));
    }
    public static function cardBlocked(): self
    {
        return new self('card_blocked', __('This card is blocked.'));
    }
    public static function cardRetired(): self
    {
        return new self('card_retired', __('This card is no longer valid.'));
    }
    public static function studentInactive(): self
    {
        return new self('student_inactive', __('This student account is inactive.'));
    }
}