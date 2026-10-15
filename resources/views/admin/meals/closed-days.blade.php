@php
    $closedSchool = \App\Models\School::find(auth()->user()->school_id);
    $closedDates = (array) data_get($closedSchool?->settings, 'calendar.closed_dates', []);
@endphp
<div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold">4. School closed days (no meals are served on these days)</div>
    <div class="card-body">
        <form method="POST" action="{{ route('admin.meals.closed-days') }}">
            @csrf
            <label class="form-label" for="closed_dates">One date per line (YYYY-MM-DD)</label>
            <textarea id="closed_dates" name="dates" rows="4" class="form-control" placeholder="2026-12-25">{{ old('dates', implode("\n", $closedDates)) }}</textarea>
            <div class="form-text mb-2">Holidays and absences are never refunded. Saving replaces the whole list.</div>
            <button class="btn btn-outline-primary">Save closed days</button>
        </form>
    </div>
</div>
