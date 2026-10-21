<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Services\Notifications\LowBalance;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

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

        $maxSale = (int) data_get($school->settings, 'till.max_sale', 0);

        // What is in force right now, so the form always shows the real figure
        $values = [
            'name' => $school->name,
            'max_sale' => $maxSale > 0 ? Money::toMajor($maxSale, $school->currency) : '',
            'topup_min' => Money::toMajor($school->minTopUp(), $school->currency),
            'khr_per_usd' => $school->khrPerUsd(),
            'low_balance' => Money::toMajor(LowBalance::thresholdFor(null, $school), $school->currency),
            'manual_entry' => (bool) data_get($school->settings, 'till.manual_entry', true),
        ];

        return view('admin.settings.index', compact('school', 'bands', 'restrict', 'days', 'windows', 'values'));
    }

    public function update(Request $request)
    {
        $school = $this->school($request);

        $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'daily' => ['nullable', 'array', 'max:4'],
            'daily.*' => ['nullable', 'numeric', 'min:0.01', 'max:100000000'],
            'weekly' => ['nullable', 'array', 'max:4'],
            'weekly.*' => ['nullable', 'numeric', 'min:0.01', 'max:100000000'],
            'max_sale' => ['nullable', 'numeric', 'min:0.01', 'max:100000000'],
            'topup_min' => ['nullable', 'numeric', 'min:0.01', 'max:100000000'],
            'khr_per_usd' => ['nullable', 'integer', 'between:1000,10000'],
            'low_balance' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'manual_entry' => ['nullable', 'boolean'],
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
                return back()->withErrors(['days' => __('Choose at least one day.')])->withInput();
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
                        ->withErrors(['windows' => __('Each buying window needs a start time that is earlier than its end time.')])
                        ->withInput();
                }

                $windows[] = [$from, $to];
            }

            $hours = ['days' => $days, 'windows' => $windows];
        }

        $settings = $school->settings ?? [];
        $settings['limits'] = $limits;
        $settings['buying_hours'] = $hours;

        // ---- the rest: a figure is only changed when the form sent it; a blank one goes back to the default
        $this->setMoney($request, $settings, 'max_sale', 'till.max_sale', $school);
        $this->setMoney($request, $settings, 'topup_min', 'topup.min', $school);
        $this->setMoney($request, $settings, 'low_balance', 'notifications.low_balance_default', $school);

        if ($request->has('khr_per_usd')) {
            $request->filled('khr_per_usd')
                ? Arr::set($settings, 'topup.khr_per_usd', (int) $request->input('khr_per_usd'))
                : Arr::forget($settings, 'topup.khr_per_usd');
        }

        if ($request->has('manual_entry')) {
            Arr::set($settings, 'till.manual_entry', $request->boolean('manual_entry'));
        }

        $attributes = ['settings' => $settings];

        if ($request->filled('name')) {
            $attributes['name'] = trim((string) $request->input('name'));
        }

        $school->update($attributes);

        return redirect()->route('admin.settings')->with('status', __('Settings saved.'));
    }

    private function school(Request $request): School
    {
        abort_unless($request->user()->school_id, 403, __('Log in as a school admin to change school settings.'));

        return School::findOrFail($request->user()->school_id);
    }

    /** Stores a money field in minor units under a dotted settings key; blank removes it (back to the default). */
    private function setMoney(Request $request, array &$settings, string $field, string $key, School $school): void
    {
        if (! $request->has($field)) {
            return;
        }

        $raw = $request->input($field);

        $raw === null || $raw === ''
            ? Arr::forget($settings, $key)
            : Arr::set($settings, $key, Money::toMinor($raw, $school->currency));
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
