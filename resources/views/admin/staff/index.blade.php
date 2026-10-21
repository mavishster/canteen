@extends('layouts.app')
@section('title', __('Staff'))

@section('content')
<h1 class="h3 mb-3">{{ __('Staff') }}</h1>

@if ($errors->any())
    <div class="alert alert-danger">
        @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
    </div>
@endif

<div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold">{{ __('New staff account') }}</div>
    <div class="card-body">
        <form method="POST" action="{{ route('admin.staff.store') }}" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-3">
                <label class="form-label" for="name">{{ __('Name') }}</label>
                <input id="name" name="name" value="{{ old('name') }}" class="form-control" required>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="email">{{ __('Email (used to sign in)') }}</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" class="form-control" required>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="role">{{ __('Role') }}</label>
                <select id="role" name="role" class="form-select" required>
                    @foreach ($roles as $role)
                        <option value="{{ $role }}" @selected(old('role') === $role)>{{ ucfirst($role) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="password">{{ __('Starting password') }}</label>
                <input id="password" name="password" type="text" minlength="12" class="form-control" autocomplete="off" required>
            </div>
            <div class="col-md-1">
                <button class="btn btn-primary w-100">{{ __('Add') }}</button>
            </div>
        </form>
        <div class="form-text mt-2">
            {{ __('Passwords need at least 12 characters, because these accounts handle money. Cashiers use the till and the sales list. Managers add reports, refunds and reconciliation. Admins also manage students, products, settings and staff.') }}
        </div>
    </div>
</div>

<div class="table-responsive">
    <table class="table bg-white align-middle">
        <thead class="table-light">
            <tr><th>{{ __('Person') }}</th><th>{{ __('Role') }}</th><th style="min-width: 220px">{{ __('Change role') }}</th><th style="min-width: 240px">{{ __('New password') }}</th><th class="text-end">{{ __('Access') }}</th></tr>
        </thead>
        <tbody>
        @forelse ($staff as $person)
            @php $role = $person->roles->first()?->name; @endphp
            <tr class="{{ $role ? '' : 'text-muted' }}">
                <td>{{ $person->name }}@if ($person->id === $meId) <span class="badge bg-light text-dark border">{{ __('you') }}</span>@endif
                    <div class="small text-muted">{{ $person->email }}</div></td>
                <td>
                    @if ($role)
                        <span class="badge bg-primary">{{ ucfirst($role) }}</span>
                    @else
                        <span class="badge bg-secondary">{{ __('Disabled') }}</span>
                    @endif
                </td>
                <td>
                    @if ($role && $person->id !== $meId)
                        <form method="POST" action="{{ route('admin.staff.role', $person) }}" class="d-flex gap-1">
                            @csrf
                            <select name="role" class="form-select form-select-sm">
                                @foreach ($roles as $option)
                                    <option value="{{ $option }}" @selected($role === $option)>{{ ucfirst($option) }}</option>
                                @endforeach
                            </select>
                            <button class="btn btn-sm btn-outline-primary">{{ __('Set') }}</button>
                        </form>
                    @endif
                </td>
                <td>
                    @if ($role)
                        <form method="POST" action="{{ route('admin.staff.password', $person) }}" class="d-flex gap-1">
                            @csrf
                            <input name="password" type="text" minlength="12" class="form-control form-control-sm" placeholder="{{ __('New password') }}" autocomplete="off" required>
                            <button class="btn btn-sm btn-outline-secondary">{{ __('Set') }}</button>
                        </form>
                    @endif
                </td>
                <td class="text-end">
                    @if ($role && $person->id !== $meId)
                        <form method="POST" action="{{ route('admin.staff.disable', $person) }}" onsubmit="return confirm('Disable {{ $person->name }}? They are signed out at once.')">
                            @csrf
                            <button class="btn btn-sm btn-outline-danger">{{ __('Disable') }}</button>
                        </form>
                    @elseif (! $role)
                        <form method="POST" action="{{ route('admin.staff.enable', $person) }}" class="d-flex gap-1 justify-content-end">
                            @csrf
                            <select name="role" class="form-select form-select-sm" style="width: 110px">
                                @foreach ($roles as $option)
                                    <option value="{{ $option }}">{{ ucfirst($option) }}</option>
                                @endforeach
                            </select>
                            <input name="password" type="text" minlength="12" class="form-control form-control-sm" style="width: 150px" placeholder="{{ __('New password') }}" autocomplete="off" required>
                            <button class="btn btn-sm btn-outline-success">{{ __('Enable') }}</button>
                        </form>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="text-center text-muted py-4">{{ __('No staff yet.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
