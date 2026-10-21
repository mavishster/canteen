@php
    $fmt = fn ($v) => \App\Support\Money::format((int) $v, $currency);
    $symbol = $currency === 'KHR' ? '៛' : '$';
    $step = $currency === 'KHR' ? '1' : '0.01';
    $dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
@endphp
@extends('layouts.app')
@section('title', __('Meals'))

@section('content')
<h1 class="h3 mb-3">{{ __('Meals and plans') }}</h1>

@if ($errors->any())
    <div class="alert alert-danger">
        @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
    </div>
@endif

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card shadow-sm mb-4">
            <div class="card-header fw-semibold">{{ __('1. Meals (breakfast, lunch...)') }}</div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.meals.types.store') }}" class="row g-2 align-items-end">
                    @csrf
                    <div class="col-md-4">
                        <label class="form-label" for="type_name">{{ __('Name') }}</label>
                        <input id="type_name" name="name" value="{{ old('name') }}" class="form-control" placeholder="{{ __('Lunch') }}" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="service_starts_at">{{ __('Served from') }}</label>
                        <input id="service_starts_at" name="service_starts_at" type="time" value="{{ old('service_starts_at') }}" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="service_ends_at">{{ __('until') }}</label>
                        <input id="service_ends_at" name="service_ends_at" type="time" value="{{ old('service_ends_at') }}" class="form-control">
                    </div>
                    <div class="col-12">
                        @foreach ($dayNames as $number => $label)
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" name="days[]" id="day{{ $number }}" value="{{ $number }}"
                                       {{ in_array($number, (array) old('days', [1, 2, 3, 4, 5])) ? 'checked' : '' }}>
                                <label class="form-check-label" for="day{{ $number }}">{{ $label }}</label>
                            </div>
                        @endforeach
                    </div>
                    <div class="col-12"><button class="btn btn-primary">{{ __('Add meal') }}</button></div>
                </form>
                <div class="form-text mt-2">{{ __('Leave the times empty to allow collection at any time of the day.') }}</div>
            </div>
            <table class="table mb-0 align-middle">
                <tbody>
                @forelse ($types as $type)
                    <tr class="{{ $type->is_active ? '' : 'text-muted' }}">
                        <td>{{ $type->name }}
                            <div class="small text-muted">
                                {{ $type->service_starts_at ? substr($type->service_starts_at, 0, 5) . '–' . substr($type->service_ends_at, 0, 5) : 'any time' }}
                                · {{ $type->available_days ? implode(' ', array_map(fn ($d) => $dayNames[$d] ?? $d, $type->available_days)) : 'every day' }}
                            </div></td>
                        <td class="text-end">
                            <form method="POST" action="{{ route('admin.meals.types.toggle', $type) }}">
                                @csrf
                                <button class="btn btn-sm btn-outline-secondary">{{ $type->is_active ? 'Switch off' : 'Switch on' }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td class="text-muted text-center py-3">{{ __('No meals yet.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card shadow-sm mb-4">
            <div class="card-header fw-semibold">{{ __('2. Plans and prices') }}</div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.meals.plans.store') }}" class="row g-2 align-items-end">
                    @csrf
                    <div class="col-md-4">
                        <label class="form-label" for="meal_type_id">{{ __('Meal') }}</label>
                        <select id="meal_type_id" name="meal_type_id" class="form-select" required>
                            @foreach ($types->where('is_active', true) as $type)
                                <option value="{{ $type->id }}" @selected(old('meal_type_id') == $type->id)>{{ $type->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="plan_name">{{ __('Plan name') }}</label>
                        <input id="plan_name" name="name" value="{{ old('name') }}" class="form-control" placeholder="{{ __('Lunch, 1 month') }}" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="duration_months">{{ __('Months') }}</label>
                        <select id="duration_months" name="duration_months" class="form-select">
                            @foreach ([1, 3, 6, 12] as $m)
                                <option value="{{ $m }}" @selected(old('duration_months') == $m)>{{ $m }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="min_grade">{{ __('From grade') }}</label>
                        <input id="min_grade" name="min_grade" type="number" min="0" max="12" value="{{ old('min_grade') }}" class="form-control" placeholder="{{ __('all') }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="max_grade">{{ __('To grade') }}</label>
                        <input id="max_grade" name="max_grade" type="number" min="0" max="12" value="{{ old('max_grade') }}" class="form-control" placeholder="{{ __('all') }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="price">Price ({{ $symbol }})</label>
                        <input id="price" name="price" type="number" min="0" step="{{ $step }}" value="{{ old('price') }}" class="form-control" required>
                    </div>
                    <div class="col-md-3"><button class="btn btn-primary w-100">{{ __('Add plan') }}</button></div>
                </form>
                <div class="form-text mt-2">{{ __('Leave both grades empty for the whole school. Kindergarten is usually grade 0 in the SIS.') }}</div>
            </div>
            <table class="table mb-0 align-middle">
                <tbody>
                @forelse ($plans as $plan)
                    <tr class="{{ $plan->is_active && $plan->mealType->is_active ? '' : 'text-muted' }}">
                        <td>{{ $plan->name }}
                            <div class="small text-muted">
                                {{ $plan->mealType->name }} · {{ $plan->duration_months }} month(s) ·
                                {{ ($plan->min_grade === null && $plan->max_grade === null) ? 'whole school' : 'grades ' . ($plan->min_grade ?? 'first') . '–' . ($plan->max_grade ?? 'last') }}
                            </div></td>
                        <td class="text-end">{{ $fmt($plan->price) }}</td>
                        <td class="text-end">
                            <form method="POST" action="{{ route('admin.meals.plans.toggle', $plan) }}">
                                @csrf
                                <button class="btn btn-sm btn-outline-secondary">{{ $plan->is_active ? 'Switch off' : 'Switch on' }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td class="text-muted text-center py-3">{{ __('No plans yet.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold">{{ __('3. Sell a plan to a student (paid from their wallet, no refunds)') }}</div>
    <div class="card-body">
        <form method="POST" action="{{ route('admin.meals.sell') }}" class="row g-2 align-items-end">
            @csrf
            <input type="hidden" name="key" value="{{ $sellKey }}">
            <div class="col-md-4">
                <label class="form-label" for="student_code">{{ __('Student number') }}</label>
                <input id="student_code" name="student_code" value="{{ old('student_code') }}" class="form-control" required>
            </div>
            <div class="col-md-5">
                <label class="form-label" for="plan_id">{{ __('Plan') }}</label>
                <select id="plan_id" name="plan_id" class="form-select" required>
                    @foreach ($plans->where('is_active', true) as $plan)
                        <option value="{{ $plan->id }}" @selected(old('plan_id') == $plan->id)>{{ $plan->name }} · {{ $fmt($plan->price) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3"><button class="btn btn-success w-100">{{ __('Sell plan') }}</button></div>
        </form>
        <div class="form-text mt-2">{{ __('If the student already has this meal paid, the new plan starts the day after the current one ends. Nobody can be paid up more than 12 months ahead.') }}</div>
    </div>
</div>

@include('admin.meals.closed-days')

<h2 class="h5">{{ __('Recent plans sold') }}</h2>
<div class="table-responsive">
    <table class="table bg-white align-middle">
        <thead class="table-light"><tr><th>{{ __('Student') }}</th><th>{{ __('Plan') }}</th><th>{{ __('From') }}</th><th>{{ __('Until') }}</th><th class="text-end">{{ __('Paid') }}</th></tr></thead>
        <tbody>
        @forelse ($subscriptions as $s)
            <tr>
                <td>{{ $s->student->name }} <span class="text-muted">({{ $s->student->student_code }})</span></td>
                <td>{{ $s->plan->name }}</td>
                <td>{{ $s->valid_from->toDateString() }}</td>
                <td>{{ $s->expires_on->toDateString() }}</td>
                <td class="text-end">{{ $fmt($s->price) }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="text-center text-muted py-4">{{ __('Nothing sold yet.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
