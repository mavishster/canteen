@php
    $fmt = fn ($v) => \App\Support\Money::format((int) $v, $currency);
    $move = fn (string $type) => (int) ($moves->get($type)->total ?? 0);
    $active = $split->get(1);
    $inactive = $split->get(0);
@endphp
@extends('layouts.app')
@section('title', __('Wallet balances'))

@section('content')
@include('manager.nav')

@if ($errors->any())
    <div class="alert alert-danger">@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
@endif

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">{{ __('Money held in student wallets') }}</h1>
    <a class="btn btn-sm btn-outline-primary" href="{{ route('manager.reports.balances.csv') }}">{{ __('Download CSV (one line per student)') }}</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card shadow-sm border-primary"><div class="card-body">
        <div class="text-muted small">{{ __('Held in wallets now') }}</div><div class="fs-3 fw-bold">{{ $fmt($total) }}</div></div></div></div>
    <div class="col-md-3"><div class="card shadow-sm"><div class="card-body">
        <div class="text-muted small">{{ __('Wallets') }}</div><div class="fs-3">{{ $count }}</div>
        <div class="small text-muted">{{ $zero }} empty</div></div></div></div>
    <div class="col-md-3"><div class="card shadow-sm"><div class="card-body">
        <div class="text-muted small">{{ __('Active students') }}</div><div class="fs-3">{{ $fmt($active->total ?? 0) }}</div>
        <div class="small text-muted">{{ $active->n ?? 0 }} wallets</div></div></div></div>
    <div class="col-md-3"><div class="card shadow-sm {{ ($inactive->total ?? 0) > 0 ? 'border-warning' : '' }}"><div class="card-body">
        <div class="text-muted small">{{ __('Inactive students (left school)') }}</div><div class="fs-3">{{ $fmt($inactive->total ?? 0) }}</div>
        <div class="small text-muted">{{ $inactive->n ?? 0 }} wallets: money to settle</div></div></div></div>
</div>

<p class="text-muted">
    {{ $belowLine }} active student(s) are below the school's low-balance line of {{ $fmt($line) }}.
</p>

<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <h2 class="h5">{{ __('By grade') }}</h2>
        <table class="table bg-white align-middle">
            <thead class="table-light"><tr><th>{{ __('Grade') }}</th><th class="text-end">{{ __('Wallets') }}</th><th class="text-end">{{ __('Held') }}</th></tr></thead>
            <tbody>
            @forelse ($byGrade as $row)
                <tr>
                    <td>{{ $row->grade === null ? 'No grade' : 'Grade ' . $row->grade }}</td>
                    <td class="text-end">{{ $row->students }}</td>
                    <td class="text-end">{{ $fmt($row->total) }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="text-center text-muted py-3">{{ __('No wallets yet.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="col-lg-6">
        <h2 class="h5">{{ __('How it moved') }}</h2>
        <form method="GET" class="row g-2 align-items-end mb-2">
            <div class="col"><label class="form-label small" for="from">{{ __('From') }}</label>
                <input id="from" name="from" type="date" value="{{ $from->toDateString() }}" class="form-control form-control-sm"></div>
            <div class="col"><label class="form-label small" for="to">{{ __('To') }}</label>
                <input id="to" name="to" type="date" value="{{ $to->toDateString() }}" class="form-control form-control-sm"></div>
            <div class="col-auto"><button class="btn btn-sm btn-primary">{{ __('Show') }}</button></div>
        </form>
        <table class="table bg-white align-middle">
            <tbody>
                <tr><td>Held at the start of {{ $from->format('j M Y') }}</td><td class="text-end">{{ $fmt($opening) }}</td></tr>
                <tr><td>{{ __('+ Top-ups') }}</td><td class="text-end">{{ $fmt($move('topup')) }}</td></tr>
                <tr><td>− Purchases (till and meal plans)</td><td class="text-end">{{ $fmt($move('purchase')) }}</td></tr>
                <tr><td>{{ __('+ Refunds') }}</td><td class="text-end">{{ $fmt($move('refund')) }}</td></tr>
                <tr><td>± Adjustments</td><td class="text-end">{{ $fmt($move('adjustment')) }}</td></tr>
                <tr class="table-light fw-semibold"><td>Held at the end of {{ $to->format('j M Y') }}</td><td class="text-end">{{ $fmt($closing) }}</td></tr>
            </tbody>
        </table>
        <p class="small text-muted">{{ __('The figures come from the money ledger, so they always add up. Purchases and adjustments show with a minus sign when money left the wallets.') }}</p>
    </div>
</div>

<h2 class="h5">{{ __('Largest balances') }}</h2>
<table class="table table-sm bg-white align-middle">
    <thead class="table-light"><tr><th>{{ __('Student') }}</th><th>{{ __('Grade') }}</th><th class="text-end">{{ __('Balance') }}</th></tr></thead>
    <tbody>
    @forelse ($largest as $row)
        <tr><td>{{ $row->student_code }} <span class="text-muted">{{ $row->name }}</span></td><td>{{ $row->grade ?? '–' }}</td><td class="text-end">{{ $fmt($row->balance) }}</td></tr>
    @empty
        <tr><td colspan="3" class="text-center text-muted py-3">{{ __('No wallets yet.') }}</td></tr>
    @endforelse
    </tbody>
</table>
@endsection
