@php
    $symbol = $school->currency === 'KHR' ? '៛' : '$';
    $step = $school->currency === 'KHR' ? '1' : '0.01';
    $dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
    $chosenDays = array_map('intval', (array) old('days', $days));
@endphp
@extends('layouts.app')
@section('title', 'School settings')

@section('content')
<h1 class="h3 mb-3">School settings · {{ $school->name }}</h1>

@if ($errors->any())
    <div class="alert alert-danger">
        @foreach ($errors->all() as $error)
            <div>{{ $error }}</div>
        @endforeach
    </div>
@endif

<form method="POST" action="{{ route('admin.settings.update') }}">
    @csrf

    <div class="card shadow-sm mb-4">
        <div class="card-header fw-semibold">Spending maximums by grade</div>
        <div class="card-body">
            <p class="text-muted small">
                Parents can set lower limits for their child, never higher. Leave a box empty for no school maximum.
            </p>
            <table class="table align-middle mb-0">
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

    <button class="btn btn-primary">Save settings</button>
</form>
@endsection
