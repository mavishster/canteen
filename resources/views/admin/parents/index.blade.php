@extends('layouts.app')
@section('title', 'Parents')

@section('content')
<h1 class="h3 mb-3">Parents</h1>

@if ($errors->any())
    <div class="alert alert-danger">
        @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
    </div>
@endif

<div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold">New parent account</div>
    <div class="card-body">
        <form method="POST" action="{{ route('admin.parents.store') }}" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-3">
                <label class="form-label" for="name">Name</label>
                <input id="name" name="name" value="{{ old('name') }}" class="form-control" required>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="email">Email (used to log in)</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" class="form-control" required>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="password">Starting password</label>
                <input id="password" name="password" type="text" minlength="8" class="form-control" autocomplete="off" required>
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary w-100">Create</button>
            </div>
        </form>
        <div class="form-text mt-2">The parent logs in to the mobile app with this email and password. At least 8 characters.</div>
    </div>
</div>

<div class="table-responsive">
    <table class="table bg-white align-middle">
        <thead class="table-light">
            <tr><th>Parent</th><th>Children</th><th style="min-width: 240px">Link a child</th><th style="min-width: 240px">New password</th></tr>
        </thead>
        <tbody>
        @forelse ($parents as $parent)
            <tr>
                <td>{{ $parent->name }}<div class="small text-muted">{{ $parent->email }}</div></td>
                <td>
                    @forelse ($links->get($parent->id, collect()) as $link)
                        <form method="POST" action="{{ route('admin.parents.unlink', $link) }}" class="d-inline">
                            @csrf
                            <span class="badge bg-light text-dark border">
                                {{ $link->student->name }} ({{ $link->student->student_code }})
                                <button class="btn btn-sm btn-link text-danger p-0 ms-1" title="Remove link" aria-label="Remove link">✕</button>
                            </span>
                        </form>
                    @empty
                        <span class="text-muted">None yet</span>
                    @endforelse
                </td>
                <td>
                    <form method="POST" action="{{ route('admin.parents.link', $parent) }}" class="d-flex gap-1">
                        @csrf
                        <select name="student_id" class="form-select form-select-sm" required>
                            <option value="">Choose a student…</option>
                            @foreach ($students as $student)
                                <option value="{{ $student->id }}">{{ $student->name }} ({{ $student->student_code }})</option>
                            @endforeach
                        </select>
                        <button class="btn btn-sm btn-outline-primary">Link</button>
                    </form>
                </td>
                <td>
                    <form method="POST" action="{{ route('admin.parents.password', $parent) }}" class="d-flex gap-1">
                        @csrf
                        <input name="password" type="text" minlength="8" class="form-control form-control-sm" placeholder="New password" autocomplete="off" required>
                        <button class="btn btn-sm btn-outline-secondary">Set</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="text-center text-muted py-4">No parent accounts yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
