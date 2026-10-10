<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Only accounts with the 'parent' role (and a school) may use the parent API. */
class EnsureParent
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->school_id || ! $user->hasRole('parent')) {
            return response()->json(['message' => 'This account cannot use the parent app.'], 403);
        }

        return $next($request);
    }
}
