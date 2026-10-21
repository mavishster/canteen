@extends('layouts.app')
@section('title', __('Dashboard'))

@section('content')
<h1 class="h3 mb-4">{{ __('Welcome, :name', ['name' => auth()->user()->name]) }}</h1>

@if (auth()->user()->roles->isEmpty())
    <div class="alert alert-warning">{{ __('Your account has no role yet. Ask an administrator to assign one.') }}</div>
@endif

<div class="row g-3">
    @hasanyrole('cashier|manager|admin|super-admin')
        <div class="col-md-4"><a class="btn btn-outline-primary w-100 py-4" href="{{ route('till') }}">{{ __('Cashier till') }}</a></div>
    @endhasanyrole
    @hasanyrole('manager|admin|super-admin')
        <div class="col-md-4"><a class="btn btn-outline-primary w-100 py-4" href="{{ route('manager') }}">{{ __('Manager') }}</a></div>
    @endhasanyrole
    @hasanyrole('admin|super-admin')
        <div class="col-md-4"><a class="btn btn-outline-primary w-100 py-4" href="{{ route('admin') }}">{{ __('Admin') }}</a></div>
    @endhasanyrole
</div>
@endsection
