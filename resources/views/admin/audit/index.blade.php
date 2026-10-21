@php
    $labels = [
        'auth.login' => 'Signed in',
        'auth.failed' => 'Failed sign-in',
        'limits.changed' => 'Spending limits changed',
        'school.settings_changed' => 'School settings changed',
        'report.exported' => 'Report exported',
        'meal_plan.sold' => 'Meal plan sold',
    ];
    $label = fn (string $action) => $labels[$action] ?? ucfirst(str_replace(['.', '_'], ' ', $action));
@endphp
@extends('layouts.app')
@section('title', __('Audit log'))

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">{{ __('Audit log') }}</h1>
    <a class="btn btn-outline-primary btn-sm" href="{{ route('admin.audit.csv', request()->query()) }}">{{ __('Download CSV') }}</a>
</div>

@if ($errors->any())
    <div class="alert alert-danger">@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
@endif

<form method="GET" class="row g-2 align-items-end mb-3">
    <div class="col-md-2">
        <label class="form-label small" for="from">{{ __('From') }}</label>
        <input id="from" name="from" type="date" value="{{ request('from') }}" class="form-control form-control-sm">
    </div>
    <div class="col-md-2">
        <label class="form-label small" for="to">{{ __('To') }}</label>
        <input id="to" name="to" type="date" value="{{ request('to') }}" class="form-control form-control-sm">
    </div>
    <div class="col-md-2">
        <label class="form-label small" for="entity">{{ __('What') }}</label>
        <select id="entity" name="entity" class="form-select form-select-sm">
            <option value="">{{ __('Everything') }}</option>
            @foreach ($entities as $entity)
                <option value="{{ $entity }}" @selected(request('entity') === $entity)>{{ ucfirst(str_replace('_', ' ', $entity)) }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label small" for="user_id">{{ __('Who') }}</label>
        <select id="user_id" name="user_id" class="form-select form-select-sm">
            <option value="">{{ __('Anyone') }}</option>
            @foreach ($actors as $actor)
                <option value="{{ $actor->user_id }}" @selected((int) request('user_id') === (int) $actor->user_id)>{{ $actor->actor_name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label small" for="q">{{ __('Search') }}</label>
        <input id="q" name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="{{ __('name or words') }}">
    </div>
    <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">{{ __('Filter') }}</button></div>
</form>

<div class="table-responsive">
    <table class="table table-sm align-middle bg-white">
        <thead class="table-light">
            <tr><th>{{ __('Time') }}</th><th>{{ __('Who') }}</th><th>{{ __('What') }}</th><th>{{ __('Details') }}</th><th>{{ __('From') }}</th></tr>
        </thead>
        <tbody>
        @forelse ($logs as $log)
            <tr>
                <td class="text-nowrap small">{{ $log->created_at->timezone($log->school?->timezone ?? 'UTC')->format('Y-m-d H:i') }}</td>
                <td>{{ $log->actor_name }}@if ($log->actor_role) <span class="badge bg-light text-dark border">{{ $log->actor_role }}</span>@endif</td>
                <td class="text-nowrap"><span class="badge bg-secondary">{{ $label($log->action) }}</span></td>
                <td>
                    {{ $log->summary }}
                    @if ($log->before || $log->after)
                        <details class="mt-1">
                            <summary class="small text-muted">{{ __('before / after') }}</summary>
                            <pre class="small mb-0">{{ json_encode(['before' => $log->before, 'after' => $log->after], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                        </details>
                    @endif
                </td>
                <td class="small text-muted">{{ $log->ip ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="text-center text-muted py-4">{{ __('Nothing recorded for these filters.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

{{ $logs->links() }}
@endsection
