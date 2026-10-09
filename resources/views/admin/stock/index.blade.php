@extends('layouts.app')
@section('title', 'Stock history')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Stock history</h1>
    <a href="{{ route('admin.products') }}" class="btn btn-outline-secondary btn-sm">← Products</a>
</div>

<div class="table-responsive">
    <table class="table table-hover align-middle bg-white">
        <thead class="table-light">
            <tr>
                <th>Time</th>
                <th>Product</th>
                <th>Type</th>
                <th class="text-end">Change</th>
                <th class="text-end">Stock after</th>
                <th>By</th>
                <th>Note</th>
            </tr>
        </thead>
        <tbody>
        @php
            $labels = ['received' => 'Received', 'sale' => 'Sale', 'adjustment' => 'Count'];
        @endphp
        @forelse ($movements as $m)
            <tr>
                <td class="text-nowrap">{{ $m->created_at->timezone($m->school->timezone)->format('Y-m-d H:i') }}</td>
                <td>{{ $m->product->name }}</td>
                <td>{{ $labels[$m->type] ?? $m->type }}</td>
                <td class="text-end {{ $m->quantity < 0 ? 'text-danger' : 'text-success' }}">{{ $m->quantity > 0 ? '+' : '' }}{{ $m->quantity }}</td>
                <td class="text-end">{{ $m->stock_after }}</td>
                <td>{{ $m->user?->name ?? '–' }}</td>
                <td class="text-muted small">{{ $m->note ?? ($m->type === 'sale' ? '' : '') }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-center text-muted py-4">No stock movements yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

{{ $movements->links() }}
@endsection
