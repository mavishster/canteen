<?php

namespace App\Services\Sis;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Asks the SIS API ("who am I?") with the parent's own SIS token. The token is never stored or logged. */
class SisHttpAuthClient implements SisAuthClient
{
    public function userForToken(string $token): ?SisTokenUser
    {
        $cfg = config('sis.api');
        $base = rtrim((string) ($cfg['base_url'] ?? ''), '/');

        if ($base === '') {
            throw new SisUnavailableException('The SIS API is not configured (SIS_API_URL).');
        }

        if (app()->isProduction() && ! str_starts_with($base, 'https://')) {
            throw new SisUnavailableException('The SIS API must use https:// in production.');
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout((int) ($cfg['timeout'] ?? 5))
                ->get($base . '/' . ltrim((string) ($cfg['user_path'] ?? '/api/user'), '/'));
        } catch (ConnectionException $e) {
            throw new SisUnavailableException('The SIS could not be reached.', 0, $e);
        }

        if ($response->serverError()) {
            throw new SisUnavailableException('The SIS answered with an error.');
        }

        if (! $response->successful()) {
            return null;   // 401, 403... the SIS does not accept this token
        }

        $path = trim(($cfg['wrapper'] ?? '') . '.' . ($cfg['id_field'] ?? 'id'), '.');
        $id = data_get($response->json(), $path);

        return is_scalar($id) && (string) $id !== '' ? new SisTokenUser((string) $id) : null;
    }
}
