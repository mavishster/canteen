<?php

namespace App\Http\Middleware;

use App\Exceptions\SisIntegrationUnavailableException;
use App\Services\SisParentService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateSisParent
{
    public function __construct(private SisParentService $sisParents) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if (! $token) {
            return response()->json(['message' => 'A valid SIS bearer token is required.'], 401);
        }

        try {
            $parentId = $this->sisParents->authenticate($token);
        } catch (SisIntegrationUnavailableException $exception) {
            Log::warning('SIS parent authentication unavailable.', ['message' => $exception->getMessage()]);

            return response()->json(['message' => 'Parent authentication is temporarily unavailable.'], 503);
        }

        if ($parentId === null) {
            return response()->json(['message' => 'The SIS bearer token is invalid or has no parent identity.'], 401);
        }

        $request->attributes->set('sis_parent_id', $parentId);

        return $next($request);
    }
}
