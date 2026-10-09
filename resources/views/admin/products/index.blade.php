@extends('layouts.app')
@section('title', 'Products')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Products</h1>
    <a href="{{ route('admin.stock') }}" class="btn btn-outline-secondary btn-sm">Stock history</a>
</div>

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
                <div class="col-md-3">
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
                <div class="col-md-2">
                    <label class="form-label" for="stock">Starting stock</label>
                    <input id="stock" name="stock" type="number" step="1" min="0" value="{{ old('stock') }}" class="form-control" placeholder="not tracked">
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary w-100">Add product</button>
                </div>
            </form>
            <div class="form-text mt-2">Leave "Starting stock" empty for items that are not counted, such as meals cooked to order.</div>
        </div>
    </div>
@endif

<div class="table-responsive">
    <table class="table table-hover align-middle bg-white">
        <thead class="table-light">
            <tr>
                <th>Name</th>
                <th>Category</th>
                <th style="width: 220px">Price</th>
                <th style="width: 300px">Stock</th>
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
                    @if ($product->track_stock)
                        <span class="badge fs-6 {{ $product->stock <= 0 ? 'bg-danger' : ($product->stock <= 5 ? 'bg-warning text-dark' : 'bg-success') }}">
                            {{ $product->stock }} in stock
                        </span>
                        <div class="d-flex gap-1 mt-2">
                            <form method="POST" action="{{ route('admin.products.receive', $product) }}" class="d-flex gap-1">
                                @csrf
                                <input name="quantity" type="number" min="1" class="form-control form-control-sm" style="width: 80px" placeholder="+ qty" required>
                                <button class="btn btn-sm btn-outline-success">Receive</button>
                            </form>
                            <form method="POST" action="{{ route('admin.products.count', $product) }}" class="d-flex gap-1">
                                @csrf
                                <input name="counted" type="number" min="0" class="form-control form-control-sm" style="width: 80px" placeholder="count" required>
                                <button class="btn btn-sm btn-outline-secondary">Set</button>
                            </form>
                        </div>
                        <form method="POST" action="{{ route('admin.products.tracking', $product) }}">
                            @csrf
                            <button class="btn btn-link btn-sm p-0 text-muted">Stop tracking</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('admin.products.tracking', $product) }}">
                            @csrf
                            <button class="btn btn-sm btn-outline-secondary">Track stock</button>
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
            <tr><td colspan="6" class="text-center text-muted py-4">No products yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
