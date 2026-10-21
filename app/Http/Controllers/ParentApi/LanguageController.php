<?php

namespace App\Http\Controllers\ParentApi;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LanguageController extends ParentBaseController
{
    /** The language in force for this call, and the ones the app may offer. */
    public function show(Request $request)
    {
        return response()->json([
            'locale' => app()->getLocale(),
            'saved' => $request->user()->locale,
            'supported' => (array) config('locales.supported'),
        ]);
    }

    /** Saves the parent's choice; from then on it wins over the Accept-Language header. */
    public function update(Request $request)
    {
        $data = $request->validate([
            'locale' => ['required', Rule::in(array_keys((array) config('locales.supported')))],
        ]);

        $request->user()->forceFill(['locale' => $data['locale']])->save();

        return response()->json(['locale' => $data['locale']]);
    }
}
