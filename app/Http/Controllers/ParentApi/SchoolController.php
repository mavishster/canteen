<?php

namespace App\Http\Controllers\ParentApi;

use App\Models\School;
use Illuminate\Http\Request;

class SchoolController extends ParentBaseController
{
    /** The school's name, colour and contact for the app header, and its currency. Works before consent is accepted. */
    public function show(Request $request)
    {
        $school = School::findOrFail($request->user()->school_id);
        $branding = (array) data_get($school->settings, 'branding', []);

        return response()->json([
            'name' => $school->name,
            'display_name' => $branding['display_name'] ?? $school->name,
            'color' => $branding['color'] ?? null,
            'contact' => $branding['contact'] ?? null,
            'currency' => $school->currency,
            'timezone' => $school->timezone,
        ]);
    }
}
