<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Home') · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
@auth
    <nav class="navbar navbar-expand navbar-dark bg-dark px-3">
        <a class="navbar-brand" href="{{ route('dashboard') }}">{{ config('app.name') }}</a>
        <ul class="navbar-nav me-auto">
            @hasanyrole('cashier|manager|admin|super-admin')
                <li class="nav-item"><a class="nav-link" href="{{ route('till') }}">Till</a></li>
                <li class="nav-item"><a class="nav-link" href="{{ route('till.meals') }}">Meal distribution</a></li>
            @endhasanyrole
            @hasanyrole('manager|admin|super-admin')
                <li class="nav-item"><a class="nav-link" href="{{ route('manager') }}">Manager</a></li>
            @endhasanyrole
            @hasanyrole('admin|super-admin')
                <li class="nav-item"><a class="nav-link" href="{{ route('admin.students') }}">Students</a></li>
                <li class="nav-item"><a class="nav-link" href="{{ route('admin.products') }}">Products</a></li>
                <li class="nav-item"><a class="nav-link" href="{{ route('admin.topups') }}">Top-ups</a></li>
                <li class="nav-item"><a class="nav-link" href="{{ route('admin.meal-plans') }}">Meal plans</a></li>
                <li class="nav-item"><a class="nav-link" href="{{ route('admin.settings') }}">Settings</a></li>
            @endhasanyrole
        </ul>
        <span class="navbar-text me-3">{{ auth()->user()->name }}</span>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="btn btn-sm btn-outline-light">Log out</button>
        </form>
    </nav>
@endauth

<main class="container py-4">
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @yield('content')
</main>

@stack('scripts')
</body>
</html>
