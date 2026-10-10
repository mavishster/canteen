@php
    $fmt = fn ($v) => \App\Support\Money::format((int) $v, $currency);
@endphp
@extends('layouts.app')
@section('title', 'Daily sales')

@section('content')
@include('manager.nav')

<form method="GET" class="d-flex gap-2 align-items-center mb-4">
    <a class="btn btn-outline-secondary" href="{{ route('manager.reports.daily', ['date' => $day->copy()->subDay()->toDateString()]) }}" aria-label="Previous day">‹</a>
    <input type="date" name="date" value="{{ $day->toDateString() }}" class="form-control" style="max-width: 200px" onchange="this.form.submit()">
    <a class="btn btn-outline-secondary" href="{{ route('manager.reports.daily', ['date' => $day->copy()->addDay()->toDateString()]) }}" aria-label="Next day">›</a>
    <span class="text-muted ms-2">{{ $day->format('l, j F Y') }} · {{ $school->timezone }}</span>
</form>

@if ($errors->any())
    <div class="alert alert-danger">
        @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
    </div>
@endif

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card shadow-sm"><div class="card-body">
        <div class="text-muted small">Sales</div><div class="fs-3">{{ $summary['count'] }}</div>
    </div></div></div>
    <div class="col-md-3"><div class="card shadow-sm"><div class="card-body">
        <div class="text-muted small">Total sold</div><div class="fs-3">{{ $fmt($summary['gross']) }}</div>
    </div></div></div>
    <div class="col-md-3"><div class="card shadow-sm"><div class="card-body">
        <div class="text-muted small">Average sale</div><div class="fs-3">{{ $fmt($summary['average']) }}</div>
    </div></div></div>
    <div class="col-md-3"><div class="card shadow-sm"><div class="card-body">
        <div class="text-muted small">Refunded sales</div>
        <div class="fs-3">{{ $summary['voided_count'] }}</div>
        <div class="small text-muted">{{ $fmt($summary['voided_total']) }}</div>
    </div></div></div>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <h2 class="h5">Items sold</h2>
        <table class="table bg-white align-middle">
            <thead class="table-light"><tr><th>Product</th><th class="text-end">Qty</th><th class="text-end">Revenue</th></tr></thead>
            <tbody>
            @forelse ($products as $row)
                <tr>
                    <td>{{ $row->name }}</td>
                    <td class="text-end">{{ $row->qty }}</td>
                    <td class="text-end">{{ $fmt($row->revenue) }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="text-center text-muted py-3">No sales on this day.</td></tr>
            @endforelse
            </tbody>
        </table>
        <p class="small text-muted">Refunded sales are not included in the items or the totals.</p>
    </div>

    <div class="col-lg-5">
        <h2 class="h5">By cashier</h2>
        <table class="table bg-white align-middle">
            <thead class="table-light"><tr><th>Cashier</th><th class="text-end">Sales</th><th class="text-end">Total</th></tr></thead>
            <tbody>
            @forelse ($cashierRows as $row)
                <tr>
                    <td>{{ $cashierNames[$row->cashier_id] ?? 'Unknown' }}</td>
                    <td class="text-end">{{ $row->sales }}</td>
                    <td class="text-end">{{ $fmt($row->total) }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="text-center text-muted py-3">No sales on this day.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
