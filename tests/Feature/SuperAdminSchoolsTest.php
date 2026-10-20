<?php

use App\Models\AuditLog;
use App\Models\ConsentDocument;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Services\ConsentService;
use App\Services\LedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

function supUser(?School $school, string $role): User
{
    Role::findOrCreate($role, 'web');

    $user = User::factory()->create(['school_id' => $school?->id]);
    $user->assignRole($role);

    return $user->refresh();
}

function supSchool(array $attributes = []): School
{
    static $n = 0;
    $n++;

    return School::create($attributes + ['name' => "Super School {$n}", 'code' => "SUP{$n}"]);
}

function supValid(array $override = []): array
{
    return $override + [
        'name' => 'New School',
        'code' => 'newsch',
        'currency' => 'USD',
        'timezone' => 'Asia/Phnom_Penh',
        'sis_branch_id' => '7',
    ];
}

// ------------------------------------------------------------------ access

it('is for super-admins only', function () {
    $school = supSchool();

    foreach (['admin', 'manager', 'cashier', 'parent'] as $role) {
        $this->actingAs(supUser($school, $role))->get('/super/schools')->assertForbidden();
        $this->actingAs(supUser($school, $role))->post('/super/schools', supValid())->assertForbidden();
    }

    auth()->logout();
    $this->get('/super/schools')->assertRedirect('/login');
});

it('sends a super-admin to the schools list and everyone else to the dashboard', function () {
    $school = supSchool();

    $this->actingAs(supUser(null, 'super-admin'))->get('/')->assertRedirect('/super/schools');
    $this->actingAs(supUser($school, 'admin'))->get('/')->assertOk()->assertSee('Welcome');
});

it('shows the Schools link only to a super-admin', function () {
    $school = supSchool();

    $this->actingAs(supUser(null, 'super-admin'))->get('/super/schools')->assertOk()->assertSee(route('super.schools'), false);
    $this->actingAs(supUser($school, 'admin'))->get('/')->assertOk()->assertDontSee(route('super.schools'), false);
});

// -------------------------------------------------------------------- list

it('lists every school with its numbers', function () {
    $a = supSchool(['name' => 'Alpha School', 'code' => 'ALPHA']);
    $b = supSchool(['name' => 'Beta School', 'code' => 'BETA', 'currency' => 'KHR']);

    $s1 = Student::create(['school_id' => $a->id, 'student_code' => '1', 'name' => 'One']);
    Student::create(['school_id' => $a->id, 'student_code' => '2', 'name' => 'Two', 'is_active' => false]);
    app(LedgerService::class)->topUp($s1->account, 2500, 'sup-1');
    supUser($a, 'parent');
    supUser($a, 'admin');
    supUser($a, 'cashier');

    $response = $this->actingAs(supUser(null, 'super-admin'))->get('/super/schools')->assertOk()->assertSee('Alpha School')->assertSee('Beta School');

    expect((int) $response->viewData('students')[$a->id]->n)->toBe(2)
        ->and((int) $response->viewData('students')[$a->id]->active)->toBe(1)
        ->and((int) $response->viewData('wallets')[$a->id])->toBe(2500)
        ->and((int) $response->viewData('parentCounts')[$a->id])->toBe(1)
        ->and((int) $response->viewData('staffCounts')[$a->id])->toBe(2)
        ->and($response->viewData('lastActivity')->has($a->id))->toBeTrue()
        ->and($response->viewData('lastActivity')->has($b->id))->toBeFalse();
});

// ------------------------------------------------------------------ create

it('creates a school with its first admin and records it', function () {
    $this->actingAs(supUser(null, 'super-admin'));

    $response = $this->post('/super/schools', supValid([
        'admin_name' => 'Head Teacher',
        'admin_email' => 'head@newschool.test',
        'admin_password' => 'a-long-password-1',
    ]));

    $school = School::where('code', 'NEWSCH')->first();
    $admin = User::where('email', 'head@newschool.test')->first();

    $response->assertRedirect(route('super.schools.show', $school));

    expect($school->name)->toBe('New School')
        ->and($school->currency)->toBe('USD')
        ->and($school->timezone)->toBe('Asia/Phnom_Penh')
        ->and((int) $school->sis_branch_id)->toBe(7)
        ->and($admin->hasRole('admin'))->toBeTrue()
        ->and($admin->school_id)->toEqual($school->id)
        ->and(Hash::check('a-long-password-1', $admin->password))->toBeTrue()
        ->and(AuditLog::withoutGlobalScopes()->where('action', 'school.created')->count())->toBe(1)
        ->and(AuditLog::withoutGlobalScopes()->where('action', 'staff.role_assigned')->count())->toBe(1);
});

it('creates a school without an admin', function () {
    $this->actingAs(supUser(null, 'super-admin'));

    $this->post('/super/schools', supValid(['sis_branch_id' => '']))->assertRedirect();

    $school = School::where('code', 'NEWSCH')->first();

    expect($school)->not->toBeNull()->and($school->sis_branch_id)->toBeNull();
});

it('refuses a bad school, a repeated code or SIS branch, and an incomplete admin', function () {
    supSchool(['code' => 'TAKEN', 'sis_branch_id' => 9]);
    $this->actingAs(supUser(null, 'super-admin'));
    $before = School::count();

    $this->post('/super/schools', supValid(['code' => 'bad code!']))->assertSessionHasErrors('code');
    $this->post('/super/schools', supValid(['code' => 'taken']))->assertSessionHasErrors('code');
    $this->post('/super/schools', supValid(['currency' => 'EUR']))->assertSessionHasErrors('currency');
    $this->post('/super/schools', supValid(['timezone' => 'Nowhere/Land']))->assertSessionHasErrors('timezone');
    $this->post('/super/schools', supValid(['sis_branch_id' => '9']))->assertSessionHasErrors('sis_branch_id');
    $this->post('/super/schools', supValid(['admin_email' => 'a@x.test']))->assertSessionHasErrors(['admin_name', 'admin_password']);
    $this->post('/super/schools', supValid(['admin_name' => 'A', 'admin_email' => 'a@x.test', 'admin_password' => 'short']))->assertSessionHasErrors('admin_password');

    expect(School::count())->toBe($before);
});

it('refuses a first admin whose email is already used, and creates no school', function () {
    $existing = supUser(supSchool(), 'cashier');
    $this->actingAs(supUser(null, 'super-admin'));
    $before = School::count();

    $this->post('/super/schools', supValid(['admin_name' => 'A', 'admin_email' => $existing->email, 'admin_password' => 'a-long-password-1']))
        ->assertSessionHasErrors('admin_email');

    expect(School::count())->toBe($before);
});

// ------------------------------------------------------------------ the page and edit

it('shows a school with its staff but not its parents', function () {
    $school = supSchool();
    $cashier = supUser($school, 'cashier');
    $cashier->update(['name' => 'Visible Cashier']);
    $parent = supUser($school, 'parent');
    $parent->update(['name' => 'Hidden Parent']);

    $this->actingAs(supUser(null, 'super-admin'))
        ->get("/super/schools/{$school->id}")
        ->assertOk()
        ->assertSee('Visible Cashier')
        ->assertDontSee('Hidden Parent');
});

it('saves the details and the look, keeping the other settings', function () {
    $school = supSchool(['settings' => ['till' => ['max_sale' => 500]]]);
    $this->actingAs(supUser(null, 'super-admin'));

    $this->post("/super/schools/{$school->id}", [
        'name' => 'Renamed School',
        'sis_branch_id' => '12',
        'display_name' => 'Happy School',
        'color' => '#1A73E8',
        'contact' => 'Office: 012 345 678',
    ])->assertRedirect(route('super.schools.show', $school));

    $school->refresh();

    expect($school->name)->toBe('Renamed School')
        ->and((int) $school->sis_branch_id)->toBe(12)
        ->and($school->settings['branding'])->toBe(['display_name' => 'Happy School', 'color' => '#1A73E8', 'contact' => 'Office: 012 345 678'])
        ->and($school->settings['till']['max_sale'])->toBe(500)
        ->and(AuditLog::withoutGlobalScopes()->where('action', 'school.updated')->first()->summary)->toContain('sis branch id');
});

it('clears the look when the boxes are emptied', function () {
    $school = supSchool(['settings' => ['branding' => ['display_name' => 'Happy', 'color' => '#112233']]]);
    $this->actingAs(supUser(null, 'super-admin'));

    $this->post("/super/schools/{$school->id}", ['name' => $school->name, 'display_name' => '', 'color' => '', 'contact' => ''])->assertRedirect();

    expect(data_get($school->refresh()->settings, 'branding.display_name'))->toBeNull()
        ->and(data_get($school->settings, 'branding.color'))->toBeNull();
});

it('refuses a bad colour and a SIS branch that belongs to another school', function () {
    supSchool(['sis_branch_id' => 5]);
    $school = supSchool();
    $this->actingAs(supUser(null, 'super-admin'));

    $this->post("/super/schools/{$school->id}", ['name' => $school->name, 'color' => 'red'])->assertSessionHasErrors('color');
    $this->post("/super/schools/{$school->id}", ['name' => $school->name, 'sis_branch_id' => '5'])->assertSessionHasErrors('sis_branch_id');
});

it('lets an empty school change currency and time zone, and fixes them once it has students', function () {
    $school = supSchool();
    $this->actingAs(supUser(null, 'super-admin'));

    $this->post("/super/schools/{$school->id}", ['name' => $school->name, 'currency' => 'KHR', 'timezone' => 'Asia/Bangkok'])->assertRedirect();
    $school->refresh();
    expect($school->currency)->toBe('KHR')->and($school->timezone)->toBe('Asia/Bangkok');

    Student::create(['school_id' => $school->id, 'student_code' => '1', 'name' => 'First Student']);

    $this->post("/super/schools/{$school->id}", ['name' => $school->name, 'currency' => 'USD'])->assertSessionHasErrors('currency');
    $this->post("/super/schools/{$school->id}", ['name' => $school->name, 'timezone' => 'UTC'])->assertSessionHasErrors('timezone');

    $school->refresh();
    expect($school->currency)->toBe('KHR')->and($school->timezone)->toBe('Asia/Bangkok');

    // sending the same values again is not a change
    $this->post("/super/schools/{$school->id}", ['name' => 'Same Currency', 'currency' => 'KHR', 'timezone' => 'Asia/Bangkok'])->assertRedirect();
    expect($school->refresh()->name)->toBe('Same Currency');
});

// ------------------------------------------------------------------ admins

it('adds an admin to a school', function () {
    $school = supSchool();
    $this->actingAs(supUser(null, 'super-admin'));

    $this->post("/super/schools/{$school->id}/admins", [
        'admin_name' => 'Deputy', 'admin_email' => 'deputy@school.test', 'admin_password' => 'a-long-password-1',
    ])->assertRedirect(route('super.schools.show', $school));

    $admin = User::where('email', 'deputy@school.test')->first();

    expect($admin->hasRole('admin'))->toBeTrue()->and($admin->school_id)->toEqual($school->id);

    $this->post("/super/schools/{$school->id}/admins", ['admin_name' => 'X', 'admin_email' => 'deputy@school.test', 'admin_password' => 'a-long-password-1'])
        ->assertSessionHasErrors('admin_email');
    $this->post("/super/schools/{$school->id}/admins", ['admin_name' => 'X', 'admin_email' => 'x@school.test', 'admin_password' => 'short'])
        ->assertSessionHasErrors('admin_password');
});

it('sets a new password for a locked-out admin and signs them out everywhere', function () {
    $school = supSchool();
    $admin = supUser($school, 'admin');
    $admin->createToken('phone');
    DB::table('sessions')->insert(['id' => 'sup-session', 'user_id' => $admin->id, 'ip_address' => null, 'user_agent' => null, 'payload' => 'x', 'last_activity' => time()]);
    $this->actingAs(supUser(null, 'super-admin'));

    $this->post("/super/schools/{$school->id}/users/{$admin->id}/password", ['password' => 'brand-new-password'])
        ->assertRedirect(route('super.schools.show', $school));

    expect(Hash::check('brand-new-password', $admin->refresh()->password))->toBeTrue()
        ->and($admin->tokens()->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $admin->id)->count())->toBe(0)
        ->and(AuditLog::withoutGlobalScopes()->where('action', 'user.password_changed')->count())->toBe(1);
});

it('never resets the password of a parent, a super-admin, or someone from another school', function () {
    $school = supSchool();
    $other = supSchool();
    $parent = supUser($school, 'parent');
    $foreign = supUser($other, 'cashier');
    $super = supUser(null, 'super-admin');
    $this->actingAs($super);

    foreach ([$parent, $foreign, $super] as $target) {
        $this->post("/super/schools/{$school->id}/users/{$target->id}/password", ['password' => 'brand-new-password'])->assertNotFound();
    }

    expect(Hash::check('brand-new-password', $parent->refresh()->password))->toBeFalse();
});

// -------------------------------------------------------------- the parent app

it('tells the parent app the school\'s name, colour and contact', function () {
    $school = supSchool(['name' => 'Plain School', 'currency' => 'KHR']);
    $parent = supUser($school, 'parent');
    Sanctum::actingAs($parent);

    $this->getJson('/api/parent/school')
        ->assertOk()
        ->assertJsonPath('name', 'Plain School')
        ->assertJsonPath('display_name', 'Plain School')   // falls back to the name
        ->assertJsonPath('color', null)
        ->assertJsonPath('currency', 'KHR');

    $school->update(['settings' => ['branding' => ['display_name' => 'Happy School', 'color' => '#112233', 'contact' => '012 345 678']]]);

    $this->getJson('/api/parent/school')
        ->assertOk()
        ->assertJsonPath('display_name', 'Happy School')
        ->assertJsonPath('color', '#112233')
        ->assertJsonPath('contact', '012 345 678');
});

it('answers the school call before the parent has accepted the consent text', function () {
    $school = supSchool(['settings' => ['consent' => ['required' => true]]]);
    app(ConsentService::class)->publish($school, str_repeat('Please accept our terms for the canteen. ', 4));
    Sanctum::actingAs(supUser($school, 'parent'));

    $this->getJson('/api/parent/children')->assertForbidden()->assertJsonPath('reason', 'consent_required');
    $this->getJson('/api/parent/school')->assertOk();
    expect(ConsentDocument::withoutGlobalScopes()->count())->toBe(1);
});

it('keeps non-parents out of the school call', function () {
    $school = supSchool();
    Sanctum::actingAs(supUser($school, 'cashier'));

    $this->getJson('/api/parent/school')->assertForbidden();
});
