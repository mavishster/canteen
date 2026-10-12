<?php

namespace App\Services\Sis;

use InvalidArgumentException;

final class SisIdentity
{
    public static function auth(): SisAuthClient
    {
        return match (config('sis.driver')) {
            'database' => app(SisHttpAuthClient::class),
            'fake' => app(FakeSisIdentity::class),
            default => throw new InvalidArgumentException("Unknown SIS driver '" . config('sis.driver') . "'."),
        };
    }

    public static function parents(): SisParentClient
    {
        return match (config('sis.driver')) {
            'database' => app(SisDatabaseParentClient::class),
            'fake' => app(FakeSisIdentity::class),
            default => throw new InvalidArgumentException("Unknown SIS driver '" . config('sis.driver') . "'."),
        };
    }
}
