#!/usr/bin/env bash
# Step 8: admin students screen + card binding. Run from the project root:  bash apply-step8.sh
set -euo pipefail

[ -f artisan ] || { echo "Run this from the project root (the folder with 'artisan')."; exit 1; }
[ -f app/Services/CardService.php ] || { echo "CardService missing: finish Step 6 first."; exit 1; }
[ -f app/Http/Controllers/AuthController.php ] || { echo "AuthController missing: finish Step 7 first."; exit 1; }

mkdir -p app/Http/Controllers/Admin app/Support resources/views/layouts resources/views/admin/students tests/Feature

# ------------------------------------------------------------ money formatter
cat > app/Support/Money.php <<'EOF'
<?php

namespace App\Support;

final class Money
{
    /** Amounts are stored in minor units: cents for USD, riel for KHR. */
    public static function format(int $amount, string $currency = 'USD'): string
    {
        if ($currency === 'KHR') {
            return number_format($amount) . ' ៛';
        }

        return '$' . number_format($amount / 100, 2);
    }
}
EOF

# ----------------------------------------------------------------- controller
cat > app/Http/Controllers/Admin/StudentController.php <<'EOF'
<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\CardException;
use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\Student;
use App\Services\CardService;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Validation\Rule;

class StudentController extends Controller
{
    public function index(Request $request)
    {
        Paginator::useBootstrapFive();

        $students = Student::with([
                'school:id,currency',
                'account',
                'cards' => fn ($q) => $q->whereIn('status', [Card::ACTIVE, Card::BLOCKED]),
            ])
            ->when($request->query('q'), function ($query, $term) {
                $query->where(fn ($w) => $w
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('student_code', 'like', "%{$term}%"));
            })
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('admin.students.index', compact('students'));
    }

    public function store(Request $request)
    {
        $schoolId = $request->user()->school_id;

        if (! $schoolId) {
            return back()->withErrors(['student_code' => 'Log in as a school admin to add students.']);
        }

        $data = $request->validate([
            'student_code' => ['required', 'string', 'max:50',
                Rule::unique('students')->where('school_id', $schoolId)],
            'name' => ['required', 'string', 'max:255'],
            'grade' => ['nullable', 'integer', 'between:1,12'],
        ]);

        Student::create($data + ['school_id' => $schoolId]);

        return redirect()->route('admin.students')->with('status', 'Student added.');
    }

    /** First card or replacement: one endpoint, the service decides. */
    public function bindCard(Request $request, Student $student, CardService $cards)
    {
        $data = $request->validate(['uid' => ['required', 'string', 'max:40']]);

        try {
            $cards->currentCard($student)
                ? $cards->replace($student, $data['uid'])
                : $cards->bind($student, $data['uid']);
        } catch (CardException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function blockCard(Card $card, CardService $cards)
    {
        return $this->change(fn () => $cards->block($card));
    }

    public function unblockCard(Card $card, CardService $cards)
    {
        return $this->change(fn () => $cards->unblock($card));
    }

    private function change(callable $action)
    {
        try {
            $action();
        } catch (CardException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 422);
        }

        return response()->json(['ok' => true]);
    }
}
EOF

# --------------------------------------------------------------------- routes
cat > routes/web.php <<'EOF'
<?php

use App\Http\Controllers\Admin\StudentController;
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

    Route::middleware('role:admin|super-admin')->group(function () {
        Route::redirect('/admin', '/admin/students')->name('admin');

        Route::get('/admin/students', [StudentController::class, 'index'])->name('admin.students');
        Route::post('/admin/students', [StudentController::class, 'store'])->name('admin.students.store');
        Route::post('/admin/students/{student}/card', [StudentController::class, 'bindCard'])->name('admin.students.card');
        Route::post('/admin/cards/{card}/block', [StudentController::class, 'blockCard'])->name('admin.cards.block');
        Route::post('/admin/cards/{card}/unblock', [StudentController::class, 'unblockCard'])->name('admin.cards.unblock');
    });
});
EOF

# --------------------------------------------------------------------- layout
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
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @yield('content')
</main>

@stack('scripts')
</body>
</html>
EOF

# ----------------------------------------------------------- students screen
cat > resources/views/admin/students/index.blade.php <<'EOF'
@extends('layouts.app')
@section('title', 'Students')

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0">Students</h1>
    <form method="GET" class="d-flex" role="search">
        <input name="q" value="{{ request('q') }}" class="form-control me-2" placeholder="Search name or code">
        <button class="btn btn-outline-secondary">Search</button>
    </form>
</div>

@if ($errors->any())
    <div class="alert alert-danger">
        @foreach ($errors->all() as $error)
            <div>{{ $error }}</div>
        @endforeach
    </div>
@endif

@if (! auth()->user()->school_id)
    <div class="alert alert-info">You are viewing all schools. Log in as a school admin to add students.</div>
@else
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="POST" action="{{ route('admin.students.store') }}" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-3">
                    <label class="form-label" for="student_code">Student code</label>
                    <input id="student_code" name="student_code" value="{{ old('student_code') }}" class="form-control" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="name">Name</label>
                    <input id="name" name="name" value="{{ old('name') }}" class="form-control" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="grade">Grade</label>
                    <input id="grade" name="grade" type="number" min="1" max="12" value="{{ old('grade') }}" class="form-control">
                </div>
                <div class="col-md-3">
                    <button class="btn btn-primary w-100">Add student</button>
                </div>
            </form>
        </div>
    </div>
@endif

<div class="table-responsive">
    <table class="table table-hover align-middle bg-white">
        <thead class="table-light">
            <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Grade</th>
                <th class="text-end">Balance</th>
                <th>Card</th>
                <th class="text-end">Actions</th>
            </tr>
        </thead>
        <tbody>
        @forelse ($students as $student)
            @php $card = $student->cards->first(); @endphp
            <tr>
                <td>{{ $student->student_code }}</td>
                <td>{{ $student->name }}</td>
                <td>{{ $student->grade ?? '–' }}</td>
                <td class="text-end">{{ \App\Support\Money::format($student->account?->balance ?? 0, $student->school->currency) }}</td>
                <td>
                    @if ($card)
                        <code>{{ $card->uid }}</code>
                        <span class="badge {{ $card->status === 'active' ? 'bg-success' : 'bg-warning text-dark' }}">{{ $card->status }}</span>
                    @else
                        <span class="text-muted">No card</span>
                    @endif
                </td>
                <td class="text-end text-nowrap">
                    <button type="button" class="btn btn-sm btn-primary js-card"
                            data-url="{{ route('admin.students.card', $student) }}"
                            data-name="{{ $student->name }}"
                            data-mode="{{ $card ? 'replace' : 'bind' }}">
                        {{ $card ? 'Replace card' : 'Bind card' }}
                    </button>
                    @if ($card)
                        <button type="button" class="btn btn-sm btn-outline-secondary js-toggle-card"
                                data-url="{{ route($card->status === 'active' ? 'admin.cards.block' : 'admin.cards.unblock', $card) }}">
                            {{ $card->status === 'active' ? 'Block' : 'Unblock' }}
                        </button>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-center text-muted py-4">No students yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

{{ $students->links() }}

<div class="modal fade" id="cardModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" id="cardForm" autocomplete="off">
            <div class="modal-header">
                <h5 class="modal-title"><span id="cardMode">Bind card</span> · <span id="cardStudent"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted mb-2">Tap the card on the reader, or type the card number, then press Enter.</p>
                <p class="small text-warning-emphasis d-none" id="cardReplaceNote">
                    The current card will stop working. The balance stays on the student's account.
                </p>
                <input id="cardUid" class="form-control form-control-lg" placeholder="Card number (UID)" required>
                <div id="cardError" class="text-danger mt-2 d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save card</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
// Vite's module script runs just before DOMContentLoaded, so wait for it
document.addEventListener('DOMContentLoaded', function () {
    var modalEl = document.getElementById('cardModal');
    var modal = new bootstrap.Modal(modalEl);
    var currentUrl = null;

    $('.js-card').on('click', function () {
        var $btn = $(this);
        currentUrl = $btn.data('url');
        var replacing = $btn.data('mode') === 'replace';

        $('#cardStudent').text($btn.data('name'));
        $('#cardMode').text(replacing ? 'Replace card' : 'Bind card');
        $('#cardReplaceNote').toggleClass('d-none', !replacing);
        $('#cardUid').val('');
        $('#cardError').addClass('d-none');
        modal.show();
    });

    // Put the cursor in the box so a USB reader can "type" straight into it
    modalEl.addEventListener('shown.bs.modal', function () {
        $('#cardUid').trigger('focus');
    });

    $('#cardForm').on('submit', function (e) {
        e.preventDefault();   // the reader's Enter key lands here

        $.post(currentUrl, { uid: $('#cardUid').val() })
            .done(function () { location.reload(); })
            .fail(function (xhr) {
                var message = (xhr.responseJSON && xhr.responseJSON.message) || 'Something went wrong.';
                $('#cardError').text(message).removeClass('d-none');
                $('#cardUid').trigger('select');
            });
    });

    $('.js-toggle-card').on('click', function () {
        $.post($(this).data('url'))
            .done(function () { location.reload(); })
            .fail(function (xhr) {
                alert((xhr.responseJSON && xhr.responseJSON.message) || 'Something went wrong.');
            });
    });
});
</script>
@endpush
EOF

# ---------------------------------------------------------------------- tests
cat > tests/Feature/AdminStudentsTest.php <<'EOF'
<?php

use App\Models\Card;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Spatie\Permission\Models\Role;

function adminOf(School $school, string $role = 'admin'): User
{
    Role::findOrCreate($role, 'web');

    $user = User::factory()->create(['school_id' => $school->id]);
    $user->assignRole($role);

    return $user;
}

it('lets an admin add a student and creates the account', function () {
    $school = School::create(['name' => 'S1', 'code' => 'S1']);
    $this->actingAs(adminOf($school));

    $this->post('/admin/students', ['student_code' => 'A1', 'name' => 'Alice', 'grade' => 3])
        ->assertRedirect();

    $student = Student::where('student_code', 'A1')->first();

    expect($student)->not->toBeNull()
        ->and($student->school_id)->toEqual($school->id)
        ->and($student->account)->not->toBeNull();
});

it('rejects a duplicate student code in the same school', function () {
    $school = School::create(['name' => 'S1', 'code' => 'S1']);
    $this->actingAs(adminOf($school));

    $this->post('/admin/students', ['student_code' => 'A1', 'name' => 'Alice']);
    $this->post('/admin/students', ['student_code' => 'A1', 'name' => 'Another'])
        ->assertSessionHasErrors('student_code');

    expect(Student::count())->toBe(1);
});

it('lists students on the page', function () {
    $school = School::create(['name' => 'S1', 'code' => 'S1']);
    Student::create(['school_id' => $school->id, 'student_code' => 'A1', 'name' => 'Alice']);
    $this->actingAs(adminOf($school));

    $this->get('/admin/students')->assertOk()->assertSee('Alice');
});

it('binds a card through the AJAX endpoint', function () {
    $school = School::create(['name' => 'S1', 'code' => 'S1']);
    $student = Student::create(['school_id' => $school->id, 'student_code' => 'A1', 'name' => 'Alice']);
    $this->actingAs(adminOf($school));

    $this->postJson("/admin/students/{$student->id}/card", ['uid' => '04:a1:b2:c3'])->assertOk();

    expect(Card::where('uid', '04A1B2C3')->exists())->toBeTrue();
});

it('replaces the card when the student already has one', function () {
    $school = School::create(['name' => 'S1', 'code' => 'S1']);
    $student = Student::create(['school_id' => $school->id, 'student_code' => 'A1', 'name' => 'Alice']);
    $this->actingAs(adminOf($school));

    $this->postJson("/admin/students/{$student->id}/card", ['uid' => '04A1B2C3'])->assertOk();
    $this->postJson("/admin/students/{$student->id}/card", ['uid' => '05D4E5F6'])->assertOk();

    expect(Card::where('uid', '04A1B2C3')->first()->status)->toBe('retired')
        ->and(Card::where('uid', '05D4E5F6')->first()->status)->toBe('active');
});

it('returns a readable message for a bad card number', function () {
    $school = School::create(['name' => 'S1', 'code' => 'S1']);
    $student = Student::create(['school_id' => $school->id, 'student_code' => 'A1', 'name' => 'Alice']);
    $this->actingAs(adminOf($school));

    $this->postJson("/admin/students/{$student->id}/card", ['uid' => 'nonsense'])
        ->assertStatus(422)
        ->assertJson(['message' => 'Card number is not valid.']);
});

it('blocks and unblocks a card', function () {
    $school = School::create(['name' => 'S1', 'code' => 'S1']);
    $student = Student::create(['school_id' => $school->id, 'student_code' => 'A1', 'name' => 'Alice']);
    $this->actingAs(adminOf($school));
    $this->postJson("/admin/students/{$student->id}/card", ['uid' => '04A1B2C3']);
    $card = Card::where('uid', '04A1B2C3')->first();

    $this->postJson("/admin/cards/{$card->id}/block")->assertOk();
    expect($card->refresh()->status)->toBe('blocked');

    $this->postJson("/admin/cards/{$card->id}/unblock")->assertOk();
    expect($card->refresh()->status)->toBe('active');
});

it('cannot touch a student from another school', function () {
    $mine = School::create(['name' => 'Mine', 'code' => 'MINE']);
    $other = School::create(['name' => 'Other', 'code' => 'OTHER']);
    $theirStudent = Student::create(['school_id' => $other->id, 'student_code' => 'X1', 'name' => 'Xavier']);

    $this->actingAs(adminOf($mine));

    $this->postJson("/admin/students/{$theirStudent->id}/card", ['uid' => '04A1B2C3'])->assertNotFound();
});

it('keeps cashiers and managers out of the student admin', function () {
    $school = School::create(['name' => 'S1', 'code' => 'S1']);

    $this->actingAs(adminOf($school, 'cashier'))->get('/admin/students')->assertForbidden();
    $this->actingAs(adminOf($school, 'manager'))->get('/admin/students')->assertForbidden();
});
EOF

# /admin now redirects to the students page, so the Step 7 test must expect that
perl -pi -e "s#get\('/admin'\)->assertOk\(\)#get('/admin')->assertRedirect('/admin/students')#" tests/Feature/AuthTest.php

echo
echo "Step 8 files created. Next:"
echo "  ./vendor/bin/pest"
echo "  composer run dev   (log in as admin@pilot.test / password, open Admin)"