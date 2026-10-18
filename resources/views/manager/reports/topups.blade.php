@php
    $fmt = fn ($v) => \App\Support\Money::format((int) $v, $currency);
    $paid = $byStatus->get('paid');
    $badges = ['paid' => 'bg-success', 'pending' => 'bg-warning text-dark', 'failed' => 'bg-danger', 'review' => 'bg-danger', 'expired' => 'bg-secondary'];
@endphp
@extends('layouts.app')
@section('title', 'Top-ups report')

@section('content')
@include('manager.nav')

<form method="GET" class="row g-2 align-items-end mb-4">
    <div class="col-md-2"><label class="form-label small" for="from">From</label>
        <input id="from" name="from" type="date" value="{{ $from->toDateString() }}" class="form-control form-control-sm"></div>
    <div class="col-md-2"><label class="form-label small" for="to">To</label>
        <input id="to" name="to" type="date" value="{{ $to->toDateString() }}" class="form-control form-control-sm"></div>
    <div class="col-md-2"><label class="form-label small" for="status">Status</label>
        <select id="status" name="status" class="form-select form-select-sm">
            <option value="">All</option>
            @foreach ($statuses as $option)
                <option value="{{ $option }}" @selected($status === $option)>{{ $option }}</option>
            @endforeach
        </select></div>
    <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Show</button></div>
    <div class="col-md-4 text-md-end">
        <a class="btn btn-sm btn-outline-primary" href="{{ route('manager.reports.topups.csv', ['from' => $from->toDateString(), 'to' => $to->toDateString()]) }}">Download CSV</a>
    </div>
</form>

@if ($errors->any())
    <div class="alert alert-danger">@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
@endif

<p class="text-muted small">{{ $from->format('j M Y') }} – {{ $to->format('j M Y') }} · school time ({{ $school->timezone }}). A paid top-up counts on the day it was paid.</p>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card shadow-sm"><div class="card-body">
        <div class="text-muted small">Paid top-ups</div><div class="fs-3">{{ $paid->n ?? 0 }}</div></div></div></div>
    <div class="col-md-3"><div class="card shadow-sm"><div class="card-body">
        <div class="text-muted small">Credited to wallets</div><div class="fs-3">{{ $fmt($paid->credited ?? 0) }}</div></div></div></div>
    <div class="col-md-3"><div class="card shadow-sm"><div class="card-body">
        <div class="text-muted small">Not paid (pending / failed / expired)</div>
        <div class="fs-3">{{ ($byStatus->get('pending')->n ?? 0) + ($byStatus->get('failed')->n ?? 0) + ($byStatus->get('expired')->n ?? 0) }}</div></div></div></div>
    <div class="col-md-3"><div class="card shadow-sm {{ ($byStatus->get('review')->n ?? 0) > 0 ? 'border-danger' : '' }}"><div class="card-body">
        <div class="text-muted small">Needs review</div><div class="fs-3">{{ $byStatus->get('review')->n ?? 0 }}</div></div></div></div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <h2 class="h5">By currency paid</h2>
        <table class="table bg-white align-middle">
            <thead class="table-light"><tr><th>Paid in</th><th class="text-end">Top-ups</th><th class="text-end">Paid</th><th class="text-end">Credited</th></tr></thead>
            <tbody>
            @forelse ($byCurrency as $row)
                <tr>
                    <td>{{ $row->pay_currency }}</td>
                    <td class="text-end">{{ $row->n }}</td>
                    <td class="text-end">{{ \App\Support\Money::format((int) $row->paid, $row->pay_currency) }}</td>
                    <td class="text-end">{{ $fmt($row->credited) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-center text-muted py-3">No paid top-ups in this period.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="col-lg-6">
        <h2 class="h5">By day</h2>
        <table class="table bg-white align-middle">
            <thead class="table-light"><tr><th>Day</th><th class="text-end">Paid top-ups</th><th class="text-end">Credited</th></tr></thead>
            <tbody>
            @forelse ($days as $day => $row)
                <tr><td>{{ $day }}</td><td class="text-end">{{ $row['count'] }}</td><td class="text-end">{{ $fmt($row['credited']) }}</td></tr>
            @empty
                <tr><td colspan="3" class="text-center text-muted py-3">No paid top-ups in this period.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<h2 class="h5">Top-ups</h2>
<div class="table-responsive">
    <table class="table table-sm align-middle bg-white">
        <thead class="table-light">
            <tr><th>Time</th><th>Student</th><th class="text-end">Credited</th><th class="text-end">Paid with</th><th>Status</th><th>Reference</th></tr>
        </thead>
        <tbody>
        @forelse ($topups as $t)
            <tr>
                <td class="text-nowrap">{{ ($t->paid_at ?? $t->created_at)->timezone($school->timezone)->format('Y-m-d H:i') }}</td>
                <td>{{ $t->student->student_code }} <span class="text-muted">{{ $t->student->name }}</span></td>
                <td class="text-end">{{ $fmt($t->amount) }}</td>
                <td class="text-end">{{ \App\Support\Money::format($t->pay_amount, $t->pay_currency) }}</td>
                <td><span class="badge {{ $badges[$t->status] ?? 'bg-secondary' }}">{{ $t->status === 'review' ? 'needs review' : $t->status }}</span></td>
                <td><code>{{ $t->gateway_ref }}</code></td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-center text-muted py-4">No top-ups in this period.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

{{ $topups->links() }}
@endsection
