<?php

namespace App\Services\Sis;

interface SisAuthClient
{
    /**
     * Asks the SIS who owns this login token. Null when the SIS does not accept it.
     *
     * @throws SisUnavailableException when the SIS cannot be asked
     */
    public function userForToken(string $token): ?SisTokenUser;
}
