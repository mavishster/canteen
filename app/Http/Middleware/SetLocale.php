<?php

namespace App\Http\Middleware;

use App\Support\I18n\LocaleResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Web pages: SetLocale. The parent API: SetLocale:sanctum (it also reads the Accept-Language header). */
class SetLocale
{
    public function handle(Request $request, Closure $next, ?string $guard = null): Response
    {
        $resolver = app(LocaleResolver::class);

        app()->setLocale($resolver->resolve($request, $request->user($guard), useHeader: $guard !== null));

        // ?lang=km on a web page is remembered for the rest of the session
        $asked = $request->query('lang');

        if ($request->hasSession() && is_string($asked) && in_array($asked, $resolver->supported(), true)) {
            $request->session()->put('locale', $asked);
        }

        return $next($request);
    }
}
