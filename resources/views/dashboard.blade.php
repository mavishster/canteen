@extends('layouts.app')
@section('title', 'Dashboard')

@section('content')
<h1 class="h3 mb-4">Welcome, {{ auth()->user()->name }}</h1>

@if (auth()->user()->roles->isEmpty())
    <div class="alert alert-warning">Your account has no role yet. Ask an administrator to assign one.</div>
@endif

<div class="row g-3">
    @hasanyrole('cashier|manager|admin|super-admin')
        <div class="col-md-4"><a class="btn btn-outline-primary w-100 py-4" href="{{ route('till') }}">Cashier till</a></div>
    @endhasanyrole
    @hasanyrole('manager|admin|super-admin')
        <div class="col-md-4"><a class="btn btn-outline-primary w-100 py-4" href="{{ route('manager') }}">Manager</a></div>
    @endhasanyrole
    @hasanyrole('admin|super-admin')
        <div class="col-md-4"><a class="btn btn-outline-primary w-100 py-4" href="{{ route('admin') }}">Admin</a></div>
    @endhasanyrole
</div>
@endsection
