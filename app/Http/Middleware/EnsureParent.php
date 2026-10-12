<?php

namespace App\Http\Middleware;

use App\Exceptions\SisLoginException;
use App\Services\Sis\SisParentAccounts;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only accounts with the 'parent' role (and a school) may use the parent API.
 * Parents who signed in through the SIS are re-checked against the SIS now and then.
 */
class EnsureParent
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->school_id || ! $user->hasRole('parent')) {
            return response()->json(['message' => 'This account cannot use the parent app.'], 403);
        }

        try {
            app(SisParentAccounts::class)->refresh($user);
        } catch (SisLoginException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], $e->status);
        }

        return $next($request);
    }
}
