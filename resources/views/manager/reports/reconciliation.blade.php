@php
    $fmt = fn ($v) => \App\Support\Money::format((int) $v, $currency);
    $types = ['topup' => 'Top-ups credited', 'purchase' => 'Purchases', 'refund' => 'Refunds', 'adjustment' => 'Adjustments'];
@endphp
@extends('layouts.app')
@section('title', 'Reconciliation')

@section('content')
@include('manager.nav')

<form method="GET" class="d-flex gap-2 align-items-center mb-4">
    <a class="btn btn-outline-secondary" href="{{ route('manager.reports.reconciliation', ['date' => $day->copy()->subDay()->toDateString()]) }}" aria-label="Previous day">‹</a>
    <input type="date" name="date" value="{{ $day->toDateString() }}" class="form-control" style="max-width: 200px" onchange="this.form.submit()">
    <a class="btn btn-outline-secondary" href="{{ route('manager.reports.reconciliation', ['date' => $day->copy()->addDay()->toDateString()]) }}" aria-label="Next day">›</a>
    <span class="text-muted ms-2">{{ $day->format('l, j F Y') }} · {{ $school->timezone }}</span>
</form>

@if ($errors->any())
    <div class="alert alert-danger">
        @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
    </div>
@endif

@if ($needReview > 0)
    <div class="alert alert-danger">
        {{ $needReview }} top-up(s) need review: the bank reported a different amount than expected. Nothing was credited for them.
        See <a href="{{ route('admin.topups') }}">Top-ups</a>.
    </div>
@endif

@if ($mismatched === 0)
    <div class="alert alert-success">Every balance matches its ledger.</div>
@else
    <div class="alert alert-danger fw-semibold">{{ $mismatched }} account(s) do not match their ledger! Stop and investigate before trusting the balances.</div>
@endif

<div class="row g-4">
    <div class="col-lg-6">
        <h2 class="h5">Wallet movements</h2>
        <table class="table bg-white align-middle">
            <thead class="table-light"><tr><th>Type</th><th class="text-end">Entries</th><th class="text-end">Amount</th></tr></thead>
            <tbody>
            @foreach ($types as $key => $label)
                @php $row = $movements->get($key); @endphp
                <tr>
                    <td>{{ $label }}</td>
                    <td class="text-end">{{ $row->entries ?? 0 }}</td>
                    <td class="text-end">{{ $fmt($row->total ?? 0) }}</td>
                </tr>
            @endforeach
            <tr class="table-light fw-semibold">
                <td>Net change in wallets</td>
                <td></td>
                <td class="text-end">{{ $fmt($movements->sum('total')) }}</td>
            </tr>
            </tbody>
        </table>
        <p class="text-muted">Held in student wallets right now: <strong>{{ $fmt($heldInWallets) }}</strong></p>
    </div>

    <div class="col-lg-6">
        <h2 class="h5">Paid top-ups (to compare with the ABA statement)</h2>
        <table class="table bg-white align-middle">
            <thead class="table-light"><tr><th>Paid in</th><th class="text-end">Top-ups</th><th class="text-end">Paid</th><th class="text-end">Credited</th></tr></thead>
            <tbody>
            @forelse ($paidTopups as $row)
                <tr>
                    <td>{{ $row->pay_currency }}</td>
                    <td class="text-end">{{ $row->n }}</td>
                    <td class="text-end">{{ \App\Support\Money::format((int) $row->paid, $row->pay_currency) }}</td>
                    <td class="text-end">{{ $fmt($row->credited) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-center text-muted py-3">No paid top-ups on this day.</td></tr>
            @endforelse
            </tbody>
        </table>

        @if ($otherTopups->isNotEmpty())
            <p class="small text-muted mb-2">
                Started this day but not paid:
                @foreach ($otherTopups as $status => $n)
                    <span class="badge bg-secondary">{{ $n }} {{ $status }}</span>
                @endforeach
            </p>
        @endif

        <a class="btn btn-outline-primary btn-sm" href="{{ route('manager.reports.topups-csv', ['date' => $day->toDateString()]) }}">Download CSV</a>
    </div>
</div>
@endsection
