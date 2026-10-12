<?php

namespace App\Services\Sis;

use RuntimeException;

/** The SIS could not be reached or is not configured. Always means "fail closed". */
class SisUnavailableException extends RuntimeException
{
}
