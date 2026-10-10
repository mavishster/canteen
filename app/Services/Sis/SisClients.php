<?php

namespace App\Services\Sis;

use InvalidArgumentException;

final class SisClients
{
    public static function default(): SisClient
    {
        return match (config('sis.driver')) {
            'database' => app(SisDatabaseClient::class),
            'fake' => app(FakeSisClient::class),
            default => throw new InvalidArgumentException("Unknown SIS driver '" . config('sis.driver') . "'."),
        };
    }
}
