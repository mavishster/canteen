@php
    $fmt = fn ($v) => \App\Support\Money::format((int) $v, $currency);
    $when = fn ($t) => $t->timezone($school->timezone)->format('Y-m-d H:i');
@endphp
@extends('layouts.app')
@section('title', 'Refunds')

@section('content')
@include('manager.nav')

@if ($errors->any())
    <div class="alert alert-danger">
        @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
    </div>
@endif

<h2 class="h5">Waiting for approval</h2>
<div class="table-responsive mb-4">
    <table class="table bg-white align-middle">
        <thead class="table-light">
            <tr><th>Sale</th><th>Student</th><th>Items</th><th class="text-end">Amount</th><th>Requested</th><th>Reason</th><th style="min-width: 260px">Decision</th></tr>
        </thead>
        <tbody>
        @forelse ($pending as $r)
            <tr>
                <td>#{{ $r->sale_id }}<div class="small text-muted">{{ $when($r->sale->created_at) }}</div></td>
                <td>{{ $r->sale->student->name }}</td>
                <td class="small">{{ $r->sale->items->map(fn ($i) => $i->quantity . '× ' . $i->name)->implode(', ') }}</td>
                <td class="text-end">{{ $fmt($r->sale->total) }}</td>
                <td>{{ $names[$r->requested_by] ?? 'Unknown' }}<div class="small text-muted">{{ $when($r->created_at) }}</div></td>
                <td>{{ $r->reason }}</td>
                <td>
                    <form method="POST" action="{{ route('manager.refunds.approve', $r) }}" class="d-flex gap-1">
                        @csrf
                        <input name="note" class="form-control form-control-sm" placeholder="Note (optional)" maxlength="255">
                        <button class="btn btn-sm btn-success">Approve</button>
                        <button class="btn btn-sm btn-outline-danger" formaction="{{ route('manager.refunds.reject', $r) }}">Reject</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-center text-muted py-4">No refund requests are waiting.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<h2 class="h5">Decided recently</h2>
<div class="table-responsive">
    <table class="table bg-white align-middle">
        <thead class="table-light">
            <tr><th>Sale</th><th>Student</th><th class="text-end">Amount</th><th>Result</th><th>Decided by</th><th>Reason / note</th></tr>
        </thead>
        <tbody>
        @forelse ($history as $r)
            <tr>
                <td>#{{ $r->sale_id }}</td>
                <td>{{ $r->sale->student->name }}</td>
                <td class="text-end">{{ $fmt($r->sale->total) }}</td>
                <td><span class="badge {{ $r->status === 'approved' ? 'bg-success' : 'bg-secondary' }}">{{ $r->status }}</span></td>
                <td>{{ $names[$r->decided_by] ?? '–' }}<div class="small text-muted">{{ $r->decided_at ? $when($r->decided_at) : '' }}</div></td>
                <td class="small">{{ $r->reason }}@if ($r->decision_note) <div class="text-muted">{{ $r->decision_note }}</div>@endif</td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-center text-muted py-4">Nothing decided yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
