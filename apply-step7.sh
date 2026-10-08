#!/usr/bin/env bash
# Step 7: login with roles. Run from the project root:  bash apply-step7.sh
set -euo pipefail

[ -f artisan ] || { echo "Run this from the project root (the folder with 'artisan')."; exit 1; }

mkdir -p app/Http/Controllers resources/views/layouts resources/views/auth database/seeders tests/Feature

# ---------------------------------------------------------------- controller
cat > app/Http/Controllers/AuthController.php <<'EOF'
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()
                ->withErrors(['email' => 'These credentials do not match our records.'])
                ->onlyInput('email');
        }

        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
EOF

# -------------------------------------------------------------------- routes
cat > routes/web.php <<'EOF'
<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::view('/', 'dashboard')->name('dashboard');

    Route::view('/till', 'placeholder', ['title' => 'Cashier till'])
        ->middleware('role:cashier|manager|admin|super-admin')->name('till');

    Route::view('/manager', 'placeholder', ['title' => 'Manager'])
        ->middleware('role:manager|admin|super-admin')->name('manager');

    Route::view('/admin', 'placeholder', ['title' => 'Admin'])
        ->middleware('role:admin|super-admin')->name('admin');
});
EOF

# --------------------------------------------------------------------- views
cat > resources/views/layouts/app.blade.php <<'EOF'
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
            @endhasanyrole
            @hasanyrole('manager|admin|super-admin')
                <li class="nav-item"><a class="nav-link" href="{{ route('manager') }}">Manager</a></li>
            @endhasanyrole
            @hasanyrole('admin|super-admin')
                <li class="nav-item"><a class="nav-link" href="{{ route('admin') }}">Admin</a></li>
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
    @yield('content')
</main>
</body>
</html>
EOF

cat > resources/views/auth/login.blade.php <<'EOF'
@extends('layouts.app')
@section('title', 'Log in')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-5 col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h1 class="h4 mb-3">Log in</h1>

                <form method="POST" action="{{ route('login') }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label" for="email">Email</label>
                        <input id="email" name="email" type="email" value="{{ old('email') }}"
                               class="form-control @error('email') is-invalid @enderror" required autofocus>
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="password">Password</label>
                        <input id="password" name="password" type="password" class="form-control" required>
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="remember" id="remember">
                        <label class="form-check-label" for="remember">Remember me</label>
                    </div>

                    <button class="btn btn-primary w-100">Log in</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
EOF

cat > resources/views/dashboard.blade.php <<'EOF'
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
EOF

cat > resources/views/placeholder.blade.php <<'EOF'
@extends('layouts.app')
@section('title', $title)

@section('content')
<h1 class="h3">{{ $title }}</h1>
<p class="text-muted">Coming soon.</p>
@endsection
EOF

# -------------------------------------------------------------------- seeder
cat > database/seeders/DemoSeeder.php <<'EOF'
<?php

namespace Database\Seeders;

use App\Models\School;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['super-admin', 'admin', 'manager', 'cashier'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $school = School::firstOrCreate(
            ['code' => 'PILOT'],
            ['name' => 'Pilot School', 'currency' => 'USD']
        );

        $users = [
            ['Super Admin', 'super@canteen.test', null, 'super-admin'],
            ['School Admin', 'admin@pilot.test', $school->id, 'admin'],
            ['Manager', 'manager@pilot.test', $school->id, 'manager'],
            ['Cashier', 'cashier@pilot.test', $school->id, 'cashier'],
        ];

        foreach ($users as [$name, $email, $schoolId, $role]) {
            $user = User::updateOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => Hash::make('password'), 'school_id' => $schoolId]
            );
            $user->syncRoles([$role]);
        }
    }
}
EOF

# --------------------------------------------------------------------- tests
cat > tests/Feature/AuthTest.php <<'EOF'
<?php

use App\Models\School;
use App\Models\User;
use Spatie\Permission\Models\Role;

function userWithRole(string $role): User
{
    $school = School::firstOrCreate(['code' => 'AUTH'], ['name' => 'Auth School']);
    Role::findOrCreate($role, 'web');

    $user = User::factory()->create(['school_id' => $school->id]);
    $user->assignRole($role);

    return $user;
}

it('redirects guests to the login page', function () {
    $this->get('/till')->assertRedirect('/login');
    $this->get('/')->assertRedirect('/login');
});

it('logs in with the correct password', function () {
    $user = userWithRole('cashier');

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
});

it('rejects a wrong password', function () {
    $user = userWithRole('cashier');

    $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('lets a cashier open the till but not the manager or admin pages', function () {
    $this->actingAs(userWithRole('cashier'));

    $this->get('/till')->assertOk();
    $this->get('/manager')->assertForbidden();
    $this->get('/admin')->assertForbidden();
});

it('lets a manager open the till and manager pages but not admin', function () {
    $this->actingAs(userWithRole('manager'));

    $this->get('/till')->assertOk();
    $this->get('/manager')->assertOk();
    $this->get('/admin')->assertForbidden();
});

it('lets an admin open every page', function () {
    $this->actingAs(userWithRole('admin'));

    $this->get('/till')->assertOk();
    $this->get('/manager')->assertOk();
    $this->get('/admin')->assertOk();
});

it('blocks a user with no role from the role pages', function () {
    $school = School::firstOrCreate(['code' => 'AUTH'], ['name' => 'Auth School']);
    $this->actingAs(User::factory()->create(['school_id' => $school->id]));

    $this->get('/')->assertOk();
    $this->get('/till')->assertForbidden();
});
EOF

# The home page now requires login, so the default example test must expect a redirect
perl -pi -e 's/assertStatus\(200\)/assertRedirect("\/login")/' tests/Feature/ExampleTest.php

echo
echo "Files created. Two manual edits are still needed:"
echo "  1. app/Models/User.php        -> add the HasRoles trait"
echo "  2. bootstrap/app.php          -> register the 'role' middleware alias"
echo "Then run:  ./vendor/bin/pest"