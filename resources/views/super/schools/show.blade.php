@php
    $fmt = fn ($v) => \App\Support\Money::format((int) $v, $school->currency);
@endphp
@extends('layouts.app')
@section('title', $school->name)

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">{{ $school->name }} <span class="text-muted fs-6">{{ $school->code }}</span></h1>
    <a href="{{ route('super.schools') }}" class="btn btn-outline-secondary btn-sm">← Schools</a>
</div>

@if ($errors->any())
    <div class="alert alert-danger">@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
@endif

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">Students</div><div class="fs-3">{{ $students }}</div></div></div></div>
    <div class="col-md-3"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">Parents</div><div class="fs-3">{{ $parents }}</div></div></div></div>
    <div class="col-md-3"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">Staff</div><div class="fs-3">{{ $staff->count() }}</div></div></div></div>
    <div class="col-md-3"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">Held in wallets</div><div class="fs-3">{{ $fmt($wallets) }}</div></div></div></div>
</div>

<form method="POST" action="{{ route('super.schools.update', $school) }}" class="card shadow-sm mb-4">
    @csrf
    <div class="card-header fw-semibold">Details and look</div>
    <div class="card-body row g-3">
        <div class="col-md-6">
            <label class="form-label" for="name">Name</label>
            <input id="name" name="name" value="{{ old('name', $school->name) }}" class="form-control" required>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="sis_branch_id">SIS branch number</label>
            <input id="sis_branch_id" name="sis_branch_id" type="number" min="1" value="{{ old('sis_branch_id', $school->sis_branch_id) }}" class="form-control" placeholder="none">
        </div>
        <div class="col-md-3"></div>

        <div class="col-md-3">
            <label class="form-label" for="currency">Currency</label>
            <select id="currency" name="currency" class="form-select" @disabled($locked)>
                @foreach (['USD', 'KHR'] as $c)
                    <option value="{{ $c }}" @selected(old('currency', $school->currency) === $c)>{{ $c }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-5">
            <label class="form-label" for="timezone">Time zone</label>
            <select id="timezone" name="timezone" class="form-select" @disabled($locked)>
                @foreach ($timezones as $tz)
                    <option value="{{ $tz }}" @selected(old('timezone', $school->timezone) === $tz)>{{ $tz }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-4 form-text align-self-end">
            {{ $locked ? 'Fixed: this school already has students or money.' : 'Can still be changed: the school is empty.' }}
        </div>

        <div class="col-md-4">
            <label class="form-label" for="display_name">Name shown in the app</label>
            <input id="display_name" name="display_name" value="{{ old('display_name', $branding['display_name'] ?? '') }}" class="form-control" maxlength="100" placeholder="{{ $school->name }}">
        </div>
        <div class="col-md-3">
            <label class="form-label" for="color">App colour</label>
            <input id="color" name="color" value="{{ old('color', $branding['color'] ?? '') }}" class="form-control" placeholder="#1a73e8" maxlength="7">
        </div>
        <div class="col-md-5">
            <label class="form-label" for="contact">Contact for parents</label>
            <input id="contact" name="contact" value="{{ old('contact', $branding['contact'] ?? '') }}" class="form-control" maxlength="200" placeholder="Office: 012 345 678">
        </div>
    </div>
    <div class="card-footer"><button class="btn btn-primary">Save</button></div>
</form>

<div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold">Staff</div>
    <table class="table mb-0 align-middle">
        <thead class="table-light"><tr><th>Person</th><th>Role</th><th style="min-width: 300px">New password</th></tr></thead>
        <tbody>
        @forelse ($staff as $person)
            @php $role = $person->roles->first()?->name; @endphp
            <tr class="{{ $role ? '' : 'text-muted' }}">
                <td>{{ $person->name }}<div class="small text-muted">{{ $person->email }}</div></td>
                <td>{{ $role ? ucfirst($role) : 'Disabled' }}</td>
                <td>
                    <form method="POST" action="{{ route('super.schools.password', [$school, $person]) }}" class="d-flex gap-1">
                        @csrf
                        <input name="password" type="text" minlength="12" class="form-control form-control-sm" placeholder="At least 12 characters" autocomplete="off" required>
                        <button class="btn btn-sm btn-outline-secondary" onclick="return confirm('Set a new password and sign {{ $person->name }} out everywhere?')">Set</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="3" class="text-center text-muted py-3">No staff yet. Add the first admin below.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<form method="POST" action="{{ route('super.schools.admins', $school) }}" class="card shadow-sm mb-4">
    @csrf
    <div class="card-header fw-semibold">Add an admin</div>
    <div class="card-body row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label" for="admin_name">Name</label>
            <input id="admin_name" name="admin_name" value="{{ old('admin_name') }}" class="form-control" required></div>
        <div class="col-md-4"><label class="form-label" for="admin_email">Email</label>
            <input id="admin_email" name="admin_email" type="email" value="{{ old('admin_email') }}" class="form-control" required></div>
        <div class="col-md-3"><label class="form-label" for="admin_password">Starting password</label>
            <input id="admin_password" name="admin_password" type="text" minlength="12" class="form-control" autocomplete="off" required></div>
        <div class="col-md-2"><button class="btn btn-primary w-100">Add admin</button></div>
    </div>
</form>
@endsection
