<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\School;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** The days the school is closed: no meals are served on them. Holidays are not refunded. */
class CalendarController extends Controller
{
    public function update(Request $request)
    {
        abort_unless($request->user()->school_id, 403, __('Log in as a school admin to change the calendar.'));

        $school = School::findOrFail($request->user()->school_id);

        $request->validate(['dates' => ['nullable', 'string', 'max:6000']]);

        $tokens = preg_split('/[\s,;]+/', trim((string) $request->input('dates', '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $dates = [];

        foreach ($tokens as $token) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $token);

            if (! $date || $date->format('Y-m-d') !== $token) {
                throw ValidationException::withMessages([
                    'dates' => __('\':token\' is not a date. Write one date per line, like 2026-12-25.', ['token' => $token]),
                ]);
            }

            $dates[] = $token;
        }

        $dates = array_values(array_unique($dates));
        sort($dates);

        if (count($dates) > 366) {
            throw ValidationException::withMessages(['dates' => __('At most 366 closed days can be listed.')]);
        }

        $settings = $school->settings ?? [];
        $settings['calendar']['closed_dates'] = $dates;
        $school->update(['settings' => $settings]);

        return redirect()->route('admin.meals')->with('status', __(':value closed day(s) saved.', ['value' => count($dates)]));
    }
}
