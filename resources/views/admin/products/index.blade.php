@extends('layouts.app')
@section('title', 'Products')

@section('content')
<h1 class="h3 mb-3">Products</h1>

@if ($errors->any())
    <div class="alert alert-danger">
        @foreach ($errors->all() as $error)
            <div>{{ $error }}</div>
        @endforeach
    </div>
@endif

@if (! auth()->user()->school_id)
    <div class="alert alert-info">You are viewing all schools. Log in as a school admin to add products.</div>
@else
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="POST" action="{{ route('admin.products.store') }}" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-4">
                    <label class="form-label" for="name">Name</label>
                    <input id="name" name="name" value="{{ old('name') }}" class="form-control" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="category">Category</label>
                    <input id="category" name="category" value="{{ old('category') }}" class="form-control" list="categoryList" placeholder="e.g. Drinks">
                    <datalist id="categoryList">
                        @foreach ($categories as $name)
                            <option value="{{ $name }}">
                        @endforeach
                    </datalist>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="price">Price ({{ $currency === 'KHR' ? '៛' : '$' }})</label>
                    <input id="price" name="price" type="number" step="{{ $currency === 'KHR' ? '1' : '0.01' }}" min="0" value="{{ old('price') }}" class="form-control" required>
                </div>
                <div class="col-md-3">
                    <button class="btn btn-primary w-100">Add product</button>
                </div>
            </form>
        </div>
    </div>
@endif

<div class="table-responsive">
    <table class="table table-hover align-middle bg-white">
        <thead class="table-light">
            <tr>
                <th>Name</th>
                <th>Category</th>
                <th style="width: 240px">Price</th>
                <th>Status</th>
                <th class="text-end">Actions</th>
            </tr>
        </thead>
        <tbody>
        @forelse ($products as $product)
            <tr class="{{ $product->is_active ? '' : 'text-muted' }}">
                <td>{{ $product->name }}</td>
                <td>{{ $product->category?->name ?? '–' }}</td>
                <td>
                    <form method="POST" action="{{ route('admin.products.update', $product) }}" class="d-flex gap-2">
                        @csrf
                        <input name="price" type="number" min="0" step="{{ $product->school->currency === 'KHR' ? '1' : '0.01' }}"
                               value="{{ \App\Support\Money::toMajor($product->price, $product->school->currency) }}"
                               class="form-control form-control-sm">
                        <button class="btn btn-sm btn-outline-primary">Save</button>
                    </form>
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
            <tr><td colspan="5" class="text-center text-muted py-4">No products yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
