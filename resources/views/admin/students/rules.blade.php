@php
    $symbol = $currency === 'KHR' ? '៛' : '$';
    $step = $currency === 'KHR' ? '1' : '0.01';
    $fmt = fn ($v) => $v !== null ? \App\Support\Money::format($v, $currency) : 'none';
@endphp
@extends('layouts.app')
@section('title', 'Rules · ' . $student->name)

@section('content')
<a href="{{ route('admin.students') }}" class="btn btn-link px-0 mb-2">← Students</a>

<h1 class="h3 mb-1">{{ $student->name }}</h1>
<p class="text-muted">
    Code {{ $student->student_code }}
    @if ($student->grade) · Grade {{ $student->grade }} @endif
</p>

@if ($errors->any())
    <div class="alert alert-danger">
        @foreach ($errors->all() as $error)
            <div>{{ $error }}</div>
        @endforeach
    </div>
@endif

<div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold">Spending limits</div>
    <div class="card-body">
        <p class="text-muted small">
            A personal limit can be lower than the school maximum, never higher. Leave a box empty for no personal limit.
        </p>
        <form method="POST" action="{{ route('admin.students.limits', $student) }}" class="row g-3 align-items-start">
            @csrf
            <div class="col-md-4">
                <label class="form-label" for="daily_limit">Daily limit ({{ $symbol }})</label>
                <input id="daily_limit" name="daily_limit" type="number" min="0" step="{{ $step }}"
                       value="{{ old('daily_limit', $account->daily_limit !== null ? \App\Support\Money::toMajor($account->daily_limit, $currency) : '') }}"
                       class="form-control" placeholder="No personal limit">
                <div class="form-text">School maximum: {{ $fmt($caps['daily']) }}</div>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="weekly_limit">Weekly limit ({{ $symbol }})</label>
                <input id="weekly_limit" name="weekly_limit" type="number" min="0" step="{{ $step }}"
                       value="{{ old('weekly_limit', $account->weekly_limit !== null ? \App\Support\Money::toMajor($account->weekly_limit, $currency) : '') }}"
                       class="form-control" placeholder="No personal limit">
                <div class="form-text">School maximum: {{ $fmt($caps['weekly']) }}</div>
            </div>
            <div class="col-md-4 pt-md-4">
                <button class="btn btn-primary mt-md-2">Save limits</button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header fw-semibold">Banned products and categories</div>
    <div class="card-body">
        <form method="POST" action="{{ route('admin.students.bans', $student) }}" class="row g-2 align-items-end mb-3">
            @csrf
            <div class="col-md-8">
                <label class="form-label" for="ban">Add a ban</label>
                <select id="ban" name="ban" class="form-select" required>
                    <option value="">Choose a product or category…</option>
                    @if ($categories->isNotEmpty())
                        <optgroup label="Whole category">
                            @foreach ($categories as $category)
                                <option value="category:{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </optgroup>
                    @endif
                    @if ($products->isNotEmpty())
                        <optgroup label="Single product">
                            @foreach ($products as $product)
                                <option value="product:{{ $product->id }}">{{ $product->name }}</option>
                            @endforeach
                        </optgroup>
                    @endif
                </select>
            </div>
            <div class="col-md-4">
                <button class="btn btn-outline-danger w-100">Ban</button>
            </div>
        </form>

        <table class="table align-middle mb-0">
            <thead class="table-light">
                <tr><th>Type</th><th>Name</th><th class="text-end">Action</th></tr>
            </thead>
            <tbody>
            @forelse ($bans as $ban)
                <tr>
                    <td>{{ $ban->category_id ? 'Category' : 'Product' }}</td>
                    <td>{{ $ban->category?->name ?? $ban->product?->name }}</td>
                    <td class="text-end">
                        <form method="POST" action="{{ route('admin.bans.delete', $ban) }}">
                            @csrf
                            <button class="btn btn-sm btn-outline-secondary">Remove</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="text-center text-muted py-3">Nothing is banned for this student.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
