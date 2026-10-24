@php
    $badge = [
        'ok' => 'bg-success',
        'invalid_card' => 'bg-danger',
        'unknown_student' => 'bg-danger',
    ];
@endphp
@extends('layouts.app')
@section('title', __('Check the cards'))

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">{{ __('Check the cards') }}</h1>
    <a href="{{ route('admin.cards.import') }}" class="btn btn-outline-secondary btn-sm">{{ __('Start again') }}</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card shadow-sm border-success"><div class="card-body">
        <div class="text-muted small">{{ __('Ready to bind') }}</div><div class="fs-3">{{ $ready }}</div></div></div></div>
    <div class="col-md-3"><div class="card shadow-sm"><div class="card-body">
        <div class="text-muted small">{{ __('Lines in the file') }}</div><div class="fs-3">{{ $total }}</div></div></div></div>
    <div class="col-md-6"><div class="card shadow-sm"><div class="card-body">
        <div class="text-muted small mb-1">{{ __('Skipped') }}</div>
        @forelse ($counts as $status => $count)
            <div class="d-flex justify-content-between"><span>{{ $labels[$status] ?? $status }}</span><strong>{{ $count }}</strong></div>
        @empty
            <div>{{ __('Nothing is skipped.') }}</div>
        @endforelse
    </div></div></div>
</div>

@if ($ready > 0)
    <form method="POST" action="{{ route('admin.cards.import.apply') }}" class="mb-4"
          onsubmit="return confirm('{{ __('Bind these cards now?') }}')">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <button class="btn btn-success btn-lg">{{ __('Bind :count card(s)', ['count' => $ready]) }}</button>
    </form>
@else
    <div class="alert alert-warning">{{ __('Nothing can be bound from this file.') }}</div>
@endif

<div class="table-responsive">
    <table class="table table-sm bg-white align-middle">
        <thead class="table-light">
            <tr><th>{{ __('Line') }}</th><th>{{ __('Student number') }}</th><th>{{ __('Card') }}</th><th>{{ __('Result') }}</th></tr>
        </thead>
        <tbody>
        @foreach ($rows as $row)
            <tr>
                <td class="text-muted">{{ $row['line'] }}</td>
                <td>{{ $row['code'] }}</td>
                <td><code>{{ $row['card'] }}</code></td>
                <td><span class="badge {{ $badge[$row['status']] ?? ($row['status'] === 'ok' ? 'bg-success' : 'bg-warning text-dark') }}">{{ $row['label'] }}</span></td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

@if ($total > count($rows))
    <p class="text-muted small">{{ __('Showing the first :count lines of :total.', ['count' => count($rows), 'total' => $total]) }}</p>
@endif
@endsection
