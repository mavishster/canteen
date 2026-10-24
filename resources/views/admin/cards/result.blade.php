@extends('layouts.app')
@section('title', __('Cards imported'))

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">{{ __('Cards imported') }}</h1>
    <a href="{{ url('/admin/students') }}" class="btn btn-outline-secondary btn-sm">{{ __('← Students') }}</a>
</div>

<div class="alert alert-success fs-5">{{ __(':count card(s) bound.', ['count' => $bound]) }}</div>

@if ($skipped)
    <div class="card shadow-sm mb-4">
        <div class="card-header fw-semibold">{{ __('Skipped') }}</div>
        <ul class="list-group list-group-flush">
            @foreach ($skipped as $label => $count)
                <li class="list-group-item d-flex justify-content-between"><span>{{ $label }}</span><strong>{{ $count }}</strong></li>
            @endforeach
        </ul>
    </div>
@endif

@if ($failures)
    <div class="card shadow-sm border-danger mb-4">
        <div class="card-header fw-semibold text-danger">{{ __(':count line(s) failed.', ['count' => count($failures)]) }}</div>
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>{{ __('Line') }}</th><th>{{ __('Student number') }}</th><th>{{ __('Card') }}</th><th>{{ __('Problem') }}</th></tr></thead>
            <tbody>
            @foreach ($failures as $failure)
                <tr><td>{{ $failure['line'] }}</td><td>{{ $failure['code'] }}</td><td><code>{{ $failure['card'] }}</code></td><td>{{ $failure['message'] }}</td></tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif

<a href="{{ route('admin.cards.import') }}" class="btn btn-primary">{{ __('Import more cards') }}</a>
@endsection
