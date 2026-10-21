@extends('layouts.app')
@section('title', __('Categories'))

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">{{ __('Product categories') }}</h1>
    <a href="{{ route('admin.category-hours') }}" class="btn btn-outline-primary btn-sm me-1">{{ __('Selling hours') }}</a><a href="{{ route('admin.products') }}" class="btn btn-outline-secondary btn-sm">{{ __('← Products') }}</a>
</div>

@if ($errors->any())
    <div class="alert alert-danger">
        @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
    </div>
@endif

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="POST" action="{{ route('admin.categories.store') }}" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-6">
                <label class="form-label" for="name">{{ __('New category') }}</label>
                <input id="name" name="name" value="{{ old('name') }}" class="form-control" placeholder="{{ __('Snacks') }}" required>
            </div>
            <div class="col-md-3"><button class="btn btn-primary w-100">{{ __('Add category') }}</button></div>
        </form>
        <div class="form-text mt-2">
            {{ __('Products choose from this list, so every category is written one way. A name that is only another way of writing an existing one (capital letters, spaces, a plural) is refused. If duplicates already exist, merge them below.') }}
        </div>
    </div>
</div>

<div class="table-responsive">
    <table class="table bg-white align-middle">
        <thead class="table-light">
            <tr><th style="min-width: 260px">{{ __('Name') }}</th><th class="text-end">{{ __('Products') }}</th><th class="text-end">{{ __('Bans') }}</th><th>{{ __('Status') }}</th><th style="min-width: 280px">{{ __('Merge into another') }}</th><th class="text-end">{{ __('Actions') }}</th></tr>
        </thead>
        <tbody>
        @forelse ($categories as $category)
            @php $banCount = (int) ($bans[$category->id] ?? 0); @endphp
            <tr class="{{ $category->is_active ? '' : 'text-muted' }}">
                <td>
                    <form method="POST" action="{{ route('admin.categories.rename', $category) }}" class="d-flex gap-1">
                        @csrf
                        <input name="name" value="{{ $category->name }}" class="form-control form-control-sm" maxlength="100" required>
                        <button class="btn btn-sm btn-outline-primary">{{ __('Rename') }}</button>
                    </form>
                </td>
                <td class="text-end">{{ $category->products_count }}</td>
                <td class="text-end">{{ $banCount }}</td>
                <td><span class="badge {{ $category->is_active ? 'bg-success' : 'bg-secondary' }}">{{ $category->is_active ? __('Available') : __('Hidden') }}</span></td>
                <td>
                    @if ($categories->count() > 1)
                        <form method="POST" action="{{ route('admin.categories.merge', $category) }}" class="d-flex gap-1"
                              onsubmit="return confirm('Move everything from this category into the chosen one and remove this category?')">
                            @csrf
                            <select name="into_id" class="form-select form-select-sm" required>
                                <option value="">{{ __('Choose…') }}</option>
                                @foreach ($categories->where('id', '!=', $category->id) as $option)
                                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                                @endforeach
                            </select>
                            <button class="btn btn-sm btn-outline-warning">{{ __('Merge') }}</button>
                        </form>
                    @endif
                </td>
                <td class="text-end text-nowrap">
                    <form method="POST" action="{{ route('admin.categories.toggle', $category) }}" class="d-inline">
                        @csrf
                        <button class="btn btn-sm btn-outline-secondary">{{ $category->is_active ? __('Hide') : __('Show') }}</button>
                    </form>
                    @if ($category->products_count === 0 && $banCount === 0)
                        <form method="POST" action="{{ route('admin.categories.delete', $category) }}" class="d-inline"
                              onsubmit="return confirm('Delete this category?')">
                            @csrf
                            <button class="btn btn-sm btn-outline-danger">{{ __('Delete') }}</button>
                        </form>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-center text-muted py-4">{{ __('No categories yet.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
