@extends('layouts.app')
@section('title', 'New school')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">New school</h1>
    <a href="{{ route('super.schools') }}" class="btn btn-outline-secondary btn-sm">← Schools</a>
</div>

@if ($errors->any())
    <div class="alert alert-danger">@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
@endif

<form method="POST" action="{{ route('super.schools.store') }}">
    @csrf

    <div class="card shadow-sm mb-4">
        <div class="card-header fw-semibold">The school</div>
        <div class="card-body row g-3">
            <div class="col-md-6">
                <label class="form-label" for="name">Name</label>
                <input id="name" name="name" value="{{ old('name') }}" class="form-control" required>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="code">Short code</label>
                <input id="code" name="code" value="{{ old('code') }}" class="form-control text-uppercase" maxlength="20" placeholder="PILOT" required>
                <div class="form-text">Letters, digits, - and _. Cannot change later.</div>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="sis_branch_id">SIS branch number</label>
                <input id="sis_branch_id" name="sis_branch_id" type="number" min="1" value="{{ old('sis_branch_id') }}" class="form-control" placeholder="none">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="currency">Currency</label>
                <select id="currency" name="currency" class="form-select" required>
                    @foreach (['USD', 'KHR'] as $c)
                        <option value="{{ $c }}" @selected(old('currency', 'USD') === $c)>{{ $c }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label" for="timezone">Time zone</label>
                <select id="timezone" name="timezone" class="form-select" required>
                    @foreach ($timezones as $tz)
                        <option value="{{ $tz }}" @selected(old('timezone', 'Asia/Phnom_Penh') === $tz)>{{ $tz }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 form-text">Currency and time zone are fixed once the school has students or money.</div>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header fw-semibold">First admin (optional)</div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label class="form-label" for="admin_name">Name</label>
                <input id="admin_name" name="admin_name" value="{{ old('admin_name') }}" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="admin_email">Email (used to sign in)</label>
                <input id="admin_email" name="admin_email" type="email" value="{{ old('admin_email') }}" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="admin_password">Starting password</label>
                <input id="admin_password" name="admin_password" type="text" minlength="12" class="form-control" autocomplete="off">
                <div class="form-text">At least 12 characters. Leave all three empty to add an admin later.</div>
            </div>
        </div>
    </div>

    <button class="btn btn-primary">Create school</button>
</form>
@endsection
