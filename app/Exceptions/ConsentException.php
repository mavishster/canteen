<?php

namespace App\Exceptions;

use RuntimeException;

class ConsentException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function noDocument(): self
    {
        return new self('no_document', 'The school has not published a text to accept yet.', 409);
    }

    public static function versionChanged(int $current): self
    {
        return new self('version_changed', "The text has changed. Please read version {$current} and accept that one.", 409);
    }
}
