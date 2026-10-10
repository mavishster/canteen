@php
    $fmt = fn ($v) => \App\Support\Money::format((int) $v, $currency);
@endphp
@extends('layouts.app')
@section('title', 'Sales')

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0">Sales · {{ $day->format('j F Y') }}</h1>
    @if ($isManager)
        <form method="GET" class="d-flex gap-2">
            <input type="date" name="date" value="{{ $day->toDateString() }}" class="form-control" onchange="this.form.submit()">
        </form>
    @endif
</div>

@if ($errors->any())
    <div class="alert alert-danger">
        @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
    </div>
@endif

<div class="table-responsive">
    <table class="table table-hover align-middle bg-white">
        <thead class="table-light">
            <tr><th>#</th><th>Time</th><th>Student</th><th>Items</th><th class="text-end">Total</th><th>Cashier</th><th style="min-width: 300px">Refund</th></tr>
        </thead>
        <tbody>
        @forelse ($sales as $sale)
            @php $refund = $refunds->get($sale->id); @endphp
            <tr class="{{ $sale->voided_at ? 'text-muted' : '' }}">
                <td>{{ $sale->id }}</td>
                <td class="text-nowrap">{{ $sale->created_at->timezone($school->timezone)->format('H:i') }}</td>
                <td>{{ $sale->student->name }}</td>
                <td class="small">{{ $sale->items->map(fn ($i) => $i->quantity . '× ' . $i->name)->implode(', ') }}</td>
                <td class="text-end">{{ $fmt($sale->total) }}</td>
                <td>{{ $cashiers[$sale->cashier_id] ?? '–' }}</td>
                <td>
                    @if ($sale->voided_at)
                        <span class="badge bg-secondary">Refunded</span>
                    @elseif ($refund && $refund->status === 'pending')
                        <span class="badge bg-warning text-dark">Refund pending</span>
                    @else
                        <form method="POST" action="{{ route('till.sales.refund', $sale) }}" class="d-flex gap-1">
                            @csrf
                            <input name="reason" class="form-control form-control-sm" placeholder="Reason" maxlength="255" required>
                            <button class="btn btn-sm btn-outline-danger text-nowrap">{{ $isManager ? 'Refund now' : 'Request refund' }}</button>
                        </form>
                        @if ($refund && $refund->status === 'rejected')
                            <div class="small text-muted mt-1">The last request was rejected.</div>
                        @endif
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-center text-muted py-4">No sales yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
