<?php

namespace App\Http\Middleware;

use App\Exceptions\SisLoginException;
use App\Services\ConsentService;
use App\Services\Sis\SisParentAccounts;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only accounts with the 'parent' role (and a school) may use the parent API.
 * Parents who signed in through the SIS are re-checked against the SIS now and then.
 * When the school requires consent, a parent who has not accepted its newest text can only use the consent screens.
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

        if (! $request->routeIs('parent.consent', 'parent.consent.accept', 'parent.logout')
            && app(ConsentService::class)->mustAccept($user)) {
            return response()->json([
                'message' => "Please read and accept the school's terms first.",
                'reason' => 'consent_required',
            ], 403);
        }

        return $next($request);
    }
}
