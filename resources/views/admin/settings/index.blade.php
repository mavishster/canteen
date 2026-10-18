@php
    $symbol = $school->currency === 'KHR' ? '៛' : '$';
    $step = $school->currency === 'KHR' ? '1' : '0.01';
    $dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
    $chosenDays = array_map('intval', (array) old('days', $days));
@endphp
@extends('layouts.app')
@section('title', 'School settings')

@section('content')
<h1 class="h3 mb-3">School settings</h1>

@if ($errors->any())
    <div class="alert alert-danger">
        @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
    </div>
@endif

<form method="POST" action="{{ route('admin.settings.update') }}">
    @csrf

    <div class="card shadow-sm mb-4">
        <div class="card-header fw-semibold">School</div>
        <div class="card-body row g-3">
            <div class="col-md-6">
                <label class="form-label" for="name">Name</label>
                <input id="name" name="name" value="{{ old('name', $values['name']) }}" class="form-control" maxlength="255" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Currency</label>
                <input class="form-control" value="{{ $school->currency }}" disabled>
            </div>
            <div class="col-md-3">
                <label class="form-label">Time zone</label>
                <input class="form-control" value="{{ $school->timezone }}" disabled>
            </div>
            <div class="col-12 form-text">
                Currency and time zone are fixed once students have money, because changing them would change every balance and every report.
                Closed days for meals are on the <a href="{{ route('admin.meals') }}">Meals</a> page.
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header fw-semibold">Spending limits</div>
        <div class="card-body">
            <p class="text-muted small">
                Maximums by grade. Parents can set lower limits for their child, never higher. Leave a box empty for no school maximum.
            </p>
            <table class="table align-middle">
                <thead class="table-light">
                    <tr><th>Grades</th><th style="width: 30%">Daily max ({{ $symbol }})</th><th style="width: 30%">Weekly max ({{ $symbol }})</th></tr>
                </thead>
                <tbody>
                @foreach ($bands as $i => $band)
                    <tr>
                        <td>{{ $band['label'] }}</td>
                        <td><input name="daily[{{ $i }}]" type="number" min="0" step="{{ $step }}" value="{{ old("daily.$i", $band['daily']) }}" class="form-control"></td>
                        <td><input name="weekly[{{ $i }}]" type="number" min="0" step="{{ $step }}" value="{{ old("weekly.$i", $band['weekly']) }}" class="form-control"></td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            <div class="row g-3">
                <div class="col-md-5">
                    <label class="form-label" for="max_sale">Most one purchase may cost ({{ $symbol }})</label>
                    <input id="max_sale" name="max_sale" type="number" min="0" step="{{ $step }}" value="{{ old('max_sale', $values['max_sale']) }}" class="form-control" placeholder="no limit">
                    <div class="form-text">
                        A lost or copied card can then never empty the account in one go. The till refuses a sale above this amount.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header fw-semibold">Buying hours</div>
        <div class="card-body">
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" role="switch" id="restrict_hours" name="restrict_hours" value="1"
                       {{ old('restrict_hours', $restrict) ? 'checked' : '' }}>
                <label class="form-check-label" for="restrict_hours">Only allow purchases at set times</label>
            </div>

            <p class="text-muted small">Times are in school time ({{ $school->timezone }}). With no time windows, the chosen days are open all day.</p>

            <div class="mb-3">
                @foreach ($dayNames as $number => $label)
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" name="days[]" id="day{{ $number }}" value="{{ $number }}"
                               {{ in_array($number, $chosenDays, true) ? 'checked' : '' }}>
                        <label class="form-check-label" for="day{{ $number }}">{{ $label }}</label>
                    </div>
                @endforeach
            </div>

            @foreach ($windows as $i => $window)
                <div class="row g-2 mb-2" style="max-width: 420px">
                    <div class="col">
                        <input name="windows[{{ $i }}][from]" type="time" class="form-control"
                               value="{{ old("windows.$i.from", $window[0]) }}" aria-label="From">
                    </div>
                    <div class="col-auto align-self-center">to</div>
                    <div class="col">
                        <input name="windows[{{ $i }}][to]" type="time" class="form-control"
                               value="{{ old("windows.$i.to", $window[1]) }}" aria-label="To">
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header fw-semibold">Top-ups</div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label class="form-label" for="topup_min">Smallest top-up ({{ $symbol }})</label>
                <input id="topup_min" name="topup_min" type="number" min="0" step="{{ $step }}" value="{{ old('topup_min', $values['topup_min']) }}" class="form-control">
                <div class="form-text">Small top-ups lose too much to card fees. Parents cannot pay less than this.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="khr_per_usd">Riel per US dollar</label>
                <input id="khr_per_usd" name="khr_per_usd" type="number" min="1000" max="10000" step="1" value="{{ old('khr_per_usd', $values['khr_per_usd']) }}" class="form-control">
                <div class="form-text">Used when a parent pays in the other currency. The amount credited is rounded down.</div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header fw-semibold">Alerts to parents</div>
        <div class="card-body row g-3">
            <div class="col-md-5">
                <label class="form-label" for="low_balance">Low balance warning below ({{ $symbol }})</label>
                <input id="low_balance" name="low_balance" type="number" min="0" step="{{ $step }}" value="{{ old('low_balance', $values['low_balance']) }}" class="form-control">
                <div class="form-text">The default for every child. Parents can change it for their own child. 0 switches the warning off.</div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header fw-semibold">Till</div>
        <div class="card-body">
            <input type="hidden" name="manual_entry" value="0">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="manual_entry" name="manual_entry" value="1"
                       {{ old('manual_entry', $values['manual_entry']) ? 'checked' : '' }}>
                <label class="form-check-label" for="manual_entry">Allow selling by student number (for a child who forgot the card)</label>
            </div>
            <div class="form-text mt-2">
                The cashier always has to confirm the child's name, and a blocked card still refuses. It also applies to the meal screen.
            </div>
        </div>
    </div>

    <button class="btn btn-primary btn-lg">Save settings</button>
</form>
@endsection
