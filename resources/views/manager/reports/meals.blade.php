@extends('layouts.app')
@section('title', 'Meals report')

@section('content')
@include('manager.nav')

<form method="GET" class="row g-2 align-items-end mb-4">
    <div class="col-md-2"><label class="form-label small" for="from">From</label>
        <input id="from" name="from" type="date" value="{{ $from->toDateString() }}" class="form-control form-control-sm"></div>
    <div class="col-md-2"><label class="form-label small" for="to">To</label>
        <input id="to" name="to" type="date" value="{{ $to->toDateString() }}" class="form-control form-control-sm"></div>
    <div class="col-md-2"><label class="form-label small" for="meal">Meal</label>
        <select id="meal" name="meal" class="form-select form-select-sm">
            <option value="">All</option>
            @foreach ($types as $type)
                <option value="{{ $type->id }}" @selected($meal === $type->id)>{{ $type->name }}</option>
            @endforeach
        </select></div>
    <div class="col-md-3"><label class="form-label small" for="q">Student</label>
        <input id="q" name="q" value="{{ $term }}" class="form-control form-control-sm" placeholder="number or name"></div>
    <div class="col-md-1"><button class="btn btn-sm btn-primary w-100">Show</button></div>
    <div class="col-md-2 text-md-end">
        <a class="btn btn-sm btn-outline-primary" href="{{ route('manager.reports.meals.csv', ['from' => $from->toDateString(), 'to' => $to->toDateString()]) }}">Download CSV</a>
    </div>
</form>

@if ($errors->any())
    <div class="alert alert-danger">@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
@endif

<p class="text-muted small">{{ $from->format('j M Y') }} – {{ $to->format('j M Y') }}. Counted by the school day each meal was served.</p>

<div class="row g-3 mb-4">
    @forelse ($types as $type)
        <div class="col-md-4">
            <div class="card shadow-sm {{ $type->is_active ? '' : 'opacity-75' }}"><div class="card-body">
                <div class="text-muted small">{{ $type->name }}{{ $type->is_active ? '' : ' (switched off)' }}</div>
                <div class="fs-3">{{ $perMeal->get($type->id)->meals ?? 0 }} <span class="fs-6 text-muted">meals in the period</span></div>
                <div class="small text-muted">
                    {{ $perMeal->get($type->id)->students ?? 0 }} different students ·
                    {{ $servedToday[$type->id] ?? 0 }} served today ·
                    {{ $withPlanToday[$type->id] ?? 0 }} have a plan today
                </div>
            </div></div>
        </div>
    @empty
        <div class="col-12"><div class="alert alert-info mb-0">No meals are set up yet (Admin, Meals).</div></div>
    @endforelse
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-5">
        <h2 class="h5">By day</h2>
        <table class="table table-sm bg-white align-middle">
            <thead class="table-light">
                <tr><th>Day</th>@foreach ($types as $type)<th class="text-end">{{ $type->name }}</th>@endforeach</tr>
            </thead>
            <tbody>
            @forelse ($byDay as $day => $counts)
                <tr>
                    <td>{{ $day }}</td>
                    @foreach ($types as $type)<td class="text-end">{{ $counts[$type->id] ?? 0 }}</td>@endforeach
                </tr>
            @empty
                <tr><td colspan="{{ $types->count() + 1 }}" class="text-center text-muted py-3">No meals were served in this period.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="col-lg-7">
        <h2 class="h5">Plans ending in the next {{ $endingDays }} days (not renewed)</h2>
        <table class="table table-sm bg-white align-middle">
            <thead class="table-light"><tr><th>Student</th><th>Meal</th><th>Last day</th></tr></thead>
            <tbody>
            @forelse ($endingSoon as $row)
                <tr>
                    <td>{{ $endingPeople[$row->student_id]->student_code ?? '?' }} <span class="text-muted">{{ $endingPeople[$row->student_id]->name ?? '' }}</span></td>
                    <td>{{ $types->firstWhere('id', $row->meal_type_id)->name ?? '?' }}</td>
                    <td>{{ \Carbon\Carbon::parse($row->ends)->toDateString() }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="text-center text-muted py-3">No plan is about to end.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<h2 class="h5">By student</h2>
<div class="table-responsive">
    <table class="table table-sm bg-white align-middle">
        <thead class="table-light">
            <tr>
                <th>Student</th><th>Grade</th>
                @foreach ($types as $type)<th class="text-end">{{ $type->name }}</th>@endforeach
                <th class="text-end">Total</th><th>Last meal</th>
            </tr>
        </thead>
        <tbody>
        @forelse ($students as $row)
            @php $person = $people[$row->student_id] ?? null; @endphp
            <tr>
                <td>{{ $person->student_code ?? '?' }} <span class="text-muted">{{ $person->name ?? '' }}</span></td>
                <td>{{ $person->grade ?? '–' }}</td>
                @foreach ($types as $type)<td class="text-end">{{ $matrix[$row->student_id][$type->id] ?? 0 }}</td>@endforeach
                <td class="text-end fw-semibold">{{ $row->total }}</td>
                <td>{{ \Carbon\Carbon::parse($row->last_date)->toDateString() }}</td>
            </tr>
        @empty
            <tr><td colspan="{{ $types->count() + 4 }}" class="text-center text-muted py-4">No meals were served in this period.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

{{ $students->links() }}
@endsection
