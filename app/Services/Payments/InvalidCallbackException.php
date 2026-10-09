<?php

namespace App\Services\Payments;

use RuntimeException;

/** The callback could not be verified (bad signature, missing reference...). Nothing is credited. */
class InvalidCallbackException extends RuntimeException
{
}
