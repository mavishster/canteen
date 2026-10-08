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
        return new self('invalid_uid', 'Card number is not valid.');
    }
    public static function uidInUse(string $uid): self
    {
        return new self('uid_in_use', "Card {$uid} is already registered.");
    }
    public static function studentHasCard(): self
    {
        return new self('student_has_card', 'This student already has a card. Use replace instead.');
    }
    public static function unknownCard(): self
    {
        return new self('unknown_card', 'Card not recognised.');
    }
    public static function cardBlocked(): self
    {
        return new self('card_blocked', 'This card is blocked.');
    }
    public static function cardRetired(): self
    {
        return new self('card_retired', 'This card is no longer valid.');
    }
    public static function studentInactive(): self
    {
        return new self('student_inactive', 'This student account is inactive.');
    }
}