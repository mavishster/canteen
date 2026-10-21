@extends('layouts.app')
@section('title', __('Products'))

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">{{ __('Products') }}</h1>
    <div>
        <a href="{{ route('admin.categories') }}" class="btn btn-outline-secondary btn-sm">{{ __('Categories') }}</a>
        <a href="{{ route('admin.stock') }}" class="btn btn-outline-secondary btn-sm">{{ __('Stock history') }}</a>
    </div>
</div>

@if ($errors->any())
    <div class="alert alert-danger">
        @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
    </div>
@endif

@if (! auth()->user()->school_id)
    <div class="alert alert-info">{{ __('You are viewing all schools. Log in as a school admin to add products.') }}</div>
@else
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="POST" action="{{ route('admin.products.store') }}" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-3">
                    <label class="form-label" for="name">{{ __('Name') }}</label>
                    <input id="name" name="name" value="{{ old('name') }}" class="form-control" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="category_id">{{ __('Category') }}</label>
                    <select id="category_id" name="category_id" class="form-select">
                        <option value="">{{ __('No category') }}</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected(old('category_id') == $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="price">Price ({{ $currency === 'KHR' ? '៛' : '$' }})</label>
                    <input id="price" name="price" type="number" step="{{ $currency === 'KHR' ? '1' : '0.01' }}" min="0" value="{{ old('price') }}" class="form-control" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="stock">{{ __('Starting stock') }}</label>
                    <input id="stock" name="stock" type="number" step="1" min="0" value="{{ old('stock') }}" class="form-control" placeholder="{{ __('not tracked') }}">
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary w-100">{{ __('Add product') }}</button>
                </div>
            </form>
            <div class="form-text mt-2">
                {{ __('Categories come from the') }} <a href="{{ route('admin.categories') }}">{{ __('categories list') }}</a>{{ __('. Leave "Starting stock" empty for items that are not counted, such as meals cooked to order.') }}
            </div>
        </div>
    </div>
@endif

<div class="table-responsive">
    <table class="table table-hover align-middle bg-white">
        <thead class="table-light">
            <tr>
                <th>{{ __('Name') }}</th>
                <th style="width: 220px">{{ __('Category') }}</th>
                <th style="width: 200px">{{ __('Price') }}</th>
                <th style="width: 300px">{{ __('Stock') }}</th>
                <th>{{ __('Status') }}</th>
                <th class="text-end">{{ __('Actions') }}</th>
            </tr>
        </thead>
        <tbody>
        @forelse ($products as $product)
            <tr class="{{ $product->is_active ? '' : 'text-muted' }}">
                <td>{{ $product->name }}</td>
                <td>
                    <form method="POST" action="{{ route('admin.products.category', $product) }}" class="d-flex gap-1">
                        @csrf
                        <select name="category_id" class="form-select form-select-sm">
                            <option value="">{{ __('No category') }}</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}" @selected((int) $product->category_id === (int) $category->id)>{{ $category->name }}</option>
                            @endforeach
                            @if ($product->category && ! $categories->contains('id', $product->category_id))
                                <option value="{{ $product->category_id }}" selected disabled>{{ $product->category->name }} (hidden)</option>
                            @endif
                        </select>
                        <button class="btn btn-sm btn-outline-secondary">{{ __('Save') }}</button>
                    </form>
                </td>
                <td>
                    <form method="POST" action="{{ route('admin.products.update', $product) }}" class="d-flex gap-2">
                        @csrf
                        <input name="price" type="number" min="0" step="{{ $product->school->currency === 'KHR' ? '1' : '0.01' }}"
                               value="{{ \App\Support\Money::toMajor($product->price, $product->school->currency) }}"
                               class="form-control form-control-sm">
                        <button class="btn btn-sm btn-outline-primary">{{ __('Save') }}</button>
                    </form>
                </td>
                <td>
                    @if ($product->track_stock)
                        <span class="badge fs-6 {{ $product->stock <= 0 ? 'bg-danger' : ($product->stock <= 5 ? 'bg-warning text-dark' : 'bg-success') }}">
                            {{ $product->stock }} in stock
                        </span>
                        <div class="d-flex gap-1 mt-2">
                            <form method="POST" action="{{ route('admin.products.receive', $product) }}" class="d-flex gap-1">
                                @csrf
                                <input name="quantity" type="number" min="1" class="form-control form-control-sm" style="width: 80px" placeholder="{{ __('+ qty') }}" required>
                                <button class="btn btn-sm btn-outline-success">{{ __('Receive') }}</button>
                            </form>
                            <form method="POST" action="{{ route('admin.products.count', $product) }}" class="d-flex gap-1">
                                @csrf
                                <input name="counted" type="number" min="0" class="form-control form-control-sm" style="width: 80px" placeholder="{{ __('count') }}" required>
                                <button class="btn btn-sm btn-outline-secondary">{{ __('Set') }}</button>
                            </form>
                        </div>
                        <form method="POST" action="{{ route('admin.products.tracking', $product) }}">
                            @csrf
                            <button class="btn btn-link btn-sm p-0 text-muted">{{ __('Stop tracking') }}</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('admin.products.tracking', $product) }}">
                            @csrf
                            <button class="btn btn-sm btn-outline-secondary">{{ __('Track stock') }}</button>
                        </form>
                    @endif
                </td>
                <td>
                    <span class="badge {{ $product->is_active ? 'bg-success' : 'bg-secondary' }}">
                        {{ $product->is_active ? 'On the till' : 'Hidden' }}
                    </span>
                </td>
                <td class="text-end">
                    <form method="POST" action="{{ route('admin.products.toggle', $product) }}">
                        @csrf
                        <button class="btn btn-sm btn-outline-secondary">{{ $product->is_active ? 'Hide' : 'Show' }}</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-center text-muted py-4">{{ __('No products yet.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
