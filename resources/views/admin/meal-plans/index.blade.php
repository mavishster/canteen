@extends('layouts.app')
@section('title', 'Meal plans')

@section('content')
<h1 class="h3 mb-3">Meal subscriptions</h1>

@if ($errors->any())
    <div class="alert alert-danger">
        @foreach ($errors->all() as $error)
            <div>{{ $error }}</div>
        @endforeach
    </div>
@endif

<div class="row g-3 mb-4">
    <div class="col-lg-5">
        <div class="card shadow-sm h-100">
            <div class="card-header fw-semibold">Configure a meal type</div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.meal-types.store') }}" class="row g-2">
                    @csrf
                    <div class="col-md-6">
                        <label class="form-label" for="meal-type-name">Name</label>
                        <input id="meal-type-name" name="name" value="{{ old('name') }}" class="form-control" placeholder="Breakfast" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="meal-type-code">Code</label>
                        <input id="meal-type-code" name="code" value="{{ old('code') }}" class="form-control" placeholder="breakfast" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="service-start">Collection starts</label>
                        <input id="service-start" name="service_starts_at" value="{{ old('service_starts_at') }}" type="time" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="service-end">Collection ends</label>
                        <input id="service-end" name="service_ends_at" value="{{ old('service_ends_at') }}" type="time" class="form-control">
                    </div>
                    <fieldset class="col-12">
                        <legend class="form-label fs-6">Available days <span class="text-muted fw-normal">(none selected means every day)</span></legend>
                        <div class="d-flex flex-wrap gap-3">
                            @foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $day => $label)
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox" name="available_days[]" value="{{ $day }}" @checked(in_array((string) $day, (array) old('available_days', []), true))>
                                    <span class="form-check-label">{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                    <div class="col-12 text-end">
                        <button class="btn btn-primary">Add meal type</button>
                    </div>
                </form>
                <p class="form-text mb-0">Meal types and collection hours are school-specific. School holiday calendars are not configured yet.</p>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card shadow-sm h-100">
            <div class="card-header fw-semibold">Create a fixed-price subscription plan</div>
            <div class="card-body">
                @if ($mealTypes->where('is_active', true)->isEmpty())
                    <div class="alert alert-info mb-0">Add an active meal type before creating plans.</div>
                @else
                    <form method="POST" action="{{ route('admin.meal-plans.store') }}" class="row g-2 align-items-end">
                        @csrf
                        <div class="col-md-6">
                            <label class="form-label" for="plan-name">Plan name</label>
                            <input id="plan-name" name="name" value="{{ old('name') }}" class="form-control" placeholder="Monthly lunch" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="plan-meal-type">Meal type</label>
                            <select id="plan-meal-type" name="meal_type_id" class="form-select" required>
                                @foreach ($mealTypes->where('is_active', true) as $mealType)
                                    <option value="{{ $mealType->id }}" @selected(old('meal_type_id') == $mealType->id)>{{ $mealType->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="plan-grade">Grade</label>
                            <select id="plan-grade" name="grade" class="form-select">
                                <option value="">All grades</option>
                                @foreach (range(1, 12) as $grade)
                                    <option value="{{ $grade }}" @selected(old('grade') == $grade)>Grade {{ $grade }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="plan-duration">Duration</label>
                            <select id="plan-duration" name="duration_months" class="form-select" required>
                                @foreach ([1 => '1 month', 3 => '3 months', 6 => '6 months', 12 => '12 months'] as $months => $label)
                                    <option value="{{ $months }}" @selected(old('duration_months', 1) == $months)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="plan-quantity">Meals included</label>
                            <input id="plan-quantity" name="entitlement_quantity" value="{{ old('entitlement_quantity') }}" type="number" min="1" max="10000" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="plan-price">Fixed price ({{ $currency }})</label>
                            <input id="plan-price" name="price" value="{{ old('price') }}" type="number" min="0.01" step="{{ $currency === 'KHR' ? '1' : '0.01' }}" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <button class="btn btn-primary w-100">Create plan</button>
                        </div>
                    </form>
                @endif
                <p class="form-text mb-0 mt-2">Prices are fixed when configured; school holidays do not change the plan price. Plans are grade-scoped because the current student records have no school-level or campus fields.</p>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold">Meal types</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>Name</th><th>Collection window</th><th>Days</th><th>Plans</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
            @forelse ($mealTypes as $mealType)
                <tr>
                    <td>{{ $mealType->name }}</td>
                    <td>{{ $mealType->service_starts_at ? substr($mealType->service_starts_at, 0, 5).'–'.substr($mealType->service_ends_at, 0, 5) : 'No time restriction' }}</td>
                    <td>
                        @if ($mealType->available_days)
                            {{ collect($mealType->available_days)->map(fn ($day) => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'][$day - 1])->join(', ') }}
                        @else
                            Every day
                        @endif
                    </td>
                    <td>{{ $mealType->plans_count }}</td>
                    <td><span class="badge {{ $mealType->is_active ? 'bg-success' : 'bg-secondary' }}">{{ $mealType->is_active ? 'Active' : 'Inactive' }}</span></td>
                    <td class="text-end">
                        <form method="POST" action="{{ route('admin.meal-types.toggle', $mealType) }}">
                            @csrf
                            <button class="btn btn-sm btn-outline-secondary">{{ $mealType->is_active ? 'Disable' : 'Enable' }}</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-4">No meal types configured.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header fw-semibold">Subscription plans</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>Name</th><th>Meal</th><th>Grade</th><th>Duration</th><th>Entitlements</th><th>Fixed price</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
            @forelse ($plans as $plan)
                <tr>
                    <td>{{ $plan->name }}</td>
                    <td>{{ $plan->mealType->name }}</td>
                    <td>{{ $plan->grade ? 'Grade '.$plan->grade : 'All grades' }}</td>
                    <td>{{ $plan->duration_months }} {{ \Illuminate\Support\Str::plural('month', $plan->duration_months) }}</td>
                    <td>{{ number_format($plan->entitlement_quantity) }}</td>
                    <td>{{ \App\Support\Money::format($plan->price, $currency) }}</td>
                    <td><span class="badge {{ $plan->is_active ? 'bg-success' : 'bg-secondary' }}">{{ $plan->is_active ? 'Active' : 'Inactive' }}</span></td>
                    <td class="text-end">
                        <form method="POST" action="{{ route('admin.meal-plans.toggle', $plan) }}">
                            @csrf
                            <button class="btn btn-sm btn-outline-secondary">{{ $plan->is_active ? 'Disable' : 'Enable' }}</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No subscription plans configured.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
