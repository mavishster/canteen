<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    /** The language switch in the menu. Works for people who have not signed in yet. */
    public function set(Request $request)
    {
        $data = $request->validate([
            'locale' => ['required', Rule::in(array_keys((array) config('locales.supported')))],
        ]);

        $request->session()->put('locale', $data['locale']);

        if ($user = $request->user()) {
            $user->forceFill(['locale' => $data['locale']])->save();   // follows them to every device
        }

        return back()->withCookie(cookie()->forever('locale', $data['locale']));
    }
}
