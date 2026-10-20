@extends('layouts.app')
@section('title', 'Schools')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Schools</h1>
    <a href="{{ route('super.schools.create') }}" class="btn btn-primary">New school</a>
</div>

<div class="table-responsive">
    <table class="table table-hover bg-white align-middle">
        <thead class="table-light">
            <tr>
                <th>School</th><th>Currency · time zone</th><th>SIS branch</th>
                <th class="text-end">Students</th><th class="text-end">Parents</th><th class="text-end">Staff</th>
                <th class="text-end">Held in wallets</th><th>Last money activity</th><th></th>
            </tr>
        </thead>
        <tbody>
        @forelse ($schools as $school)
            @php $s = $students->get($school->id); $last = $lastActivity[$school->id] ?? null; @endphp
            <tr>
                <td>{{ $school->name }}<div class="small text-muted">{{ $school->code }}</div></td>
                <td>{{ $school->currency }} · {{ $school->timezone }}</td>
                <td>{{ $school->sis_branch_id ?? '–' }}</td>
                <td class="text-end">{{ $s->active ?? 0 }} <span class="text-muted">/ {{ $s->n ?? 0 }}</span></td>
                <td class="text-end">{{ $parentCounts[$school->id] ?? 0 }}</td>
                <td class="text-end">{{ $staffCounts[$school->id] ?? 0 }}</td>
                <td class="text-end">{{ \App\Support\Money::format((int) ($wallets[$school->id] ?? 0), $school->currency) }}</td>
                <td class="small">{{ $last ? \Carbon\Carbon::parse($last)->timezone($school->timezone)->format('Y-m-d H:i') : '–' }}</td>
                <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('super.schools.show', $school) }}">Open</a></td>
            </tr>
        @empty
            <tr><td colspan="9" class="text-center text-muted py-4">No schools yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<p class="small text-muted">Students shows active / all. Staff are admins, managers and cashiers.</p>
@endsection
