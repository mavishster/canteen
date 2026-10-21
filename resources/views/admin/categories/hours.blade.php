@php
    $dayNames = [1 => __('Mon'), 2 => __('Tue'), 3 => __('Wed'), 4 => __('Thu'), 5 => __('Fri'), 6 => __('Sat'), 7 => __('Sun')];
@endphp
@extends('layouts.app')
@section('title', __('Selling hours'))

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">{{ __('Selling hours by category') }}</h1>
    <a href="{{ route('admin.categories') }}" class="btn btn-outline-secondary btn-sm">{{ __('← Categories') }}</a>
</div>

@if ($errors->any())
    <div class="alert alert-danger">@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
@endif

<p class="text-muted">
    {{ __('Times are school time (:timezone).', ['timezone' => $school->timezone]) }} {{ __('A category with nothing set can be bought at any time. If the cart holds an item from a closed category, the till refuses the whole sale and says when it is open. These hours come on top of the school\'s general buying hours (Settings).') }}
</p>

<div class="table-responsive">
    <table class="table bg-white align-middle">
        <thead class="table-light">
            <tr><th>{{ __('Category') }}</th><th style="min-width: 360px">{{ __('Open from – until') }}</th><th style="min-width: 360px">{{ __('Days') }}</th><th class="text-end">{{ __('Actions') }}</th></tr>
        </thead>
        <tbody>
        @forelse ($categories as $category)
            @php
                $chosen = $category->sell_days ?: [1, 2, 3, 4, 5, 6, 7];
                $formId = 'hours-' . $category->id;
            @endphp
            <tr class="{{ $category->is_active ? '' : 'text-muted' }}">
                <td>
                    {{ $category->name }}
                    <div class="small text-muted">{{ __(':count product(s)', ['count' => $category->products_count]) }}</div>
                    @if ($category->hasSellingHours())
                        <span class="badge bg-warning text-dark">{{ __('restricted') }}</span>
                    @endif
                </td>
                <td>
                    <div class="d-flex gap-2 align-items-center">
                        <input form="{{ $formId }}" name="sell_from" type="time" value="{{ $category->sell_from ? substr($category->sell_from, 0, 5) : '' }}" class="form-control form-control-sm" style="width: 130px" aria-label="{{ __('From') }}">
                        <span>{{ __('to') }}</span>
                        <input form="{{ $formId }}" name="sell_to" type="time" value="{{ $category->sell_to ? substr($category->sell_to, 0, 5) : '' }}" class="form-control form-control-sm" style="width: 130px" aria-label="{{ __('Until') }}">
                    </div>
                </td>
                <td>
                    @foreach ($dayNames as $number => $label)
                        <div class="form-check form-check-inline">
                            <input form="{{ $formId }}" class="form-check-input" type="checkbox" name="days[]" id="d{{ $category->id }}-{{ $number }}" value="{{ $number }}" {{ in_array($number, $chosen) ? 'checked' : '' }}>
                            <label class="form-check-label" for="d{{ $category->id }}-{{ $number }}">{{ $label }}</label>
                        </div>
                    @endforeach
                </td>
                <td class="text-end text-nowrap">
                    <form id="{{ $formId }}" method="POST" action="{{ route('admin.category-hours.update', $category) }}" class="d-inline">
                        @csrf
                        <button class="btn btn-sm btn-outline-primary">{{ __('Save') }}</button>
                    </form>
                    @if ($category->hasSellingHours())
                        <form method="POST" action="{{ route('admin.category-hours.clear', $category) }}" class="d-inline">
                            @csrf
                            <button class="btn btn-sm btn-outline-secondary">{{ __('Clear') }}</button>
                        </form>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="text-center text-muted py-4">{{ __('No categories yet. Add them under Categories.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
