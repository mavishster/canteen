<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Money;
use Illuminate\Http\Request;

class SchoolSettingsController extends Controller
{
    /** Grade bands for the school-wide spending caps. */
    private const BANDS = [[1, 3], [4, 6], [7, 9], [10, 12]];

    public function edit(Request $request)
    {
        $school = $this->school($request);

        $bands = [];
        foreach (self::BANDS as [$min, $max]) {
            $bands[] = [
                'label' => "Grades {$min}–{$max}",
                'daily' => $this->capValue($school, 'daily', $min, $max),
                'weekly' => $this->capValue($school, 'weekly', $min, $max),
            ];
        }

        $hours = $school->buyingHours();
        $restrict = $hours !== null;
        $days = $hours['days'] ?? [1, 2, 3, 4, 5];
        $windows = array_pad($hours['windows'] ?? [], 3, ['', '']);

        return view('admin.settings.index', compact('school', 'bands', 'restrict', 'days', 'windows'));
    }

    public function update(Request $request)
    {
        $school = $this->school($request);

        $request->validate([
            'daily' => ['nullable', 'array', 'max:4'],
            'daily.*' => ['nullable', 'numeric', 'min:0.01', 'max:100000000'],
            'weekly' => ['nullable', 'array', 'max:4'],
            'weekly.*' => ['nullable', 'numeric', 'min:0.01', 'max:100000000'],
            'days' => ['nullable', 'array'],
            'days.*' => ['integer', 'between:1,7'],
            'windows' => ['nullable', 'array', 'max:3'],
            'windows.*.from' => ['nullable', 'date_format:H:i'],
            'windows.*.to' => ['nullable', 'date_format:H:i'],
        ]);

        // ---- spending caps (blank = no cap for that band)
        $limits = ['daily' => [], 'weekly' => []];

        foreach (self::BANDS as $i => [$min, $max]) {
            foreach (['daily', 'weekly'] as $kind) {
                $raw = $request->input("{$kind}.{$i}");

                if ($raw !== null && $raw !== '') {
                    $limits[$kind][] = [$min, $max, Money::toMinor($raw, $school->currency)];
                }
            }
        }

        // ---- buying hours (unchecked = no restriction)
        $hours = null;

        if ($request->boolean('restrict_hours')) {
            $days = collect($request->input('days', []))->map(fn ($d) => (int) $d)->unique()->sort()->values()->all();

            if (! $days) {
                return back()->withErrors(['days' => 'Choose at least one day.'])->withInput();
            }

            $windows = [];
            foreach ((array) $request->input('windows', []) as $window) {
                $from = $window['from'] ?? null;
                $to = $window['to'] ?? null;

                if (! $from && ! $to) {
                    continue;
                }

                if (! $from || ! $to || $from >= $to) {
                    return back()
                        ->withErrors(['windows' => 'Each buying window needs a start time that is earlier than its end time.'])
                        ->withInput();
                }

                $windows[] = [$from, $to];
            }

            $hours = ['days' => $days, 'windows' => $windows];
        }

        $settings = $school->settings ?? [];
        $settings['limits'] = $limits;
        $settings['buying_hours'] = $hours;

        $school->update(['settings' => $settings]);

        return redirect()->route('admin.settings')->with('status', 'Settings saved.');
    }

    private function school(Request $request): School
    {
        abort_unless($request->user()->school_id, 403, 'Log in as a school admin to change school settings.');

        return School::findOrFail($request->user()->school_id);
    }

    private function capValue(School $school, string $kind, int $min, int $max): string
    {
        foreach (data_get($school->settings, "limits.{$kind}", []) as [$from, $to, $cap]) {
            if ((int) $from === $min && (int) $to === $max) {
                return Money::toMajor((int) $cap, $school->currency);
            }
        }

        return '';
    }
}
