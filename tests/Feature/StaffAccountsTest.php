<?php

use App\Models\School;
use App\Models\User;
use Database\Seeders\DemoCanteenSeeder;
use Database\Seeders\DemoRulesSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

function stfSchool(string $code = 'STF'): School
{
    return School::firstOrCreate(['code' => $code], ['name' => "School {$code}"]);
}

function stfUser(School $school, string $role): User
{
    Role::findOrCreate($role, 'web');

    $user = User::factory()->create(['school_id' => $school->id]);
    $user->assignRole($role);

    return $user;
}

function stfSession(User $user, string $id): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'ip_address' => null,
        'user_agent' => null,
        'payload' => 'x',
        'last_activity' => time(),
    ]);
}

// ----------------------------------------------------------- creating staff

it('lets an admin create staff with a role', function () {
    $school = stfSchool();
    $this->actingAs(stfUser($school, 'admin'));

    $this->post('/admin/staff', [
        'name' => 'Sok Cashier',
        'email' => 'sok@example.test',
        'role' => 'cashier',
        'password' => 'a-long-password-1',
    ])->assertRedirect(route('admin.staff'));

    $user = User::where('email', 'sok@example.test')->first();

    expect($user->hasRole('cashier'))->toBeTrue()
        ->and($user->school_id)->toEqual($school->id)
        ->and(Hash::check('a-long-password-1', $user->password))->toBeTrue();
});

it('rejects weak passwords, duplicate emails and roles an admin may not hand out', function () {
    $school = stfSchool();
    $existing = stfUser($school, 'manager');
    $this->actingAs(stfUser($school, 'admin'));

    $ok = ['name' => 'X', 'email' => 'x@example.test', 'role' => 'cashier', 'password' => 'a-long-password-1'];

    $this->post('/admin/staff', ['password' => 'short'] + $ok)->assertSessionHasErrors('password');
    $this->post('/admin/staff', ['email' => $existing->email] + $ok)->assertSessionHasErrors('email');
    $this->post('/admin/staff', ['role' => 'super-admin'] + $ok)->assertSessionHasErrors('role');
    $this->post('/admin/staff', ['role' => 'parent'] + $ok)->assertSessionHasErrors('role');

    expect(User::where('email', 'x@example.test')->exists())->toBeFalse();
});

it('lists only this school\'s staff, shows disabled people, and hides parents', function () {
    $school = stfSchool();
    $admin = stfUser($school, 'admin');
    $cashier = stfUser($school, 'cashier');
    $cashier->update(['name' => 'Visible Cashier']);
    $parent = stfUser($school, 'parent');
    $parent->update(['name' => 'Hidden Parent']);
    $other = stfUser(stfSchool('OTHERSTF'), 'cashier');
    $other->update(['name' => 'Other School Cashier']);
    $gone = stfUser($school, 'manager');
    $gone->update(['name' => 'Gone Manager']);
    $gone->syncRoles([]);

    $this->actingAs($admin)->get('/admin/staff')
        ->assertOk()
        ->assertSee('Visible Cashier')
        ->assertSee('Gone Manager')
        ->assertSee('Disabled')
        ->assertDontSee('Hidden Parent')
        ->assertDontSee('Other School Cashier');
});

// ------------------------------------------------------------ changing roles

it('changes a role, but never your own', function () {
    $school = stfSchool();
    $admin = stfUser($school, 'admin');
    $cashier = stfUser($school, 'cashier');
    $this->actingAs($admin);

    $this->post("/admin/staff/{$cashier->id}/role", ['role' => 'manager'])->assertRedirect();
    expect($cashier->refresh()->hasRole('manager'))->toBeTrue()->and($cashier->hasRole('cashier'))->toBeFalse();

    $this->post("/admin/staff/{$admin->id}/role", ['role' => 'cashier'])->assertSessionHasErrors('staff');
    expect($admin->refresh()->hasRole('admin'))->toBeTrue();
});

it('refuses to change the role of a disabled account', function () {
    $school = stfSchool();
    $gone = stfUser($school, 'cashier');
    $gone->syncRoles([]);
    $this->actingAs(stfUser($school, 'admin'));

    $this->post("/admin/staff/{$gone->id}/role", ['role' => 'manager'])->assertSessionHasErrors('staff');

    expect($gone->refresh()->roles)->toHaveCount(0);
});

// ----------------------------------------------------------------- passwords

it('sets a new password and signs the person out everywhere', function () {
    $school = stfSchool();
    $cashier = stfUser($school, 'cashier');
    $cashier->createToken('phone');
    stfSession($cashier, 'session-1');
    $this->actingAs(stfUser($school, 'admin'));

    $this->post("/admin/staff/{$cashier->id}/password", ['password' => 'brand-new-password'])->assertRedirect();

    expect(Hash::check('brand-new-password', $cashier->refresh()->password))->toBeTrue()
        ->and($cashier->tokens()->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $cashier->id)->count())->toBe(0);
});

it('rejects a short new password', function () {
    $school = stfSchool();
    $cashier = stfUser($school, 'cashier');
    $this->actingAs(stfUser($school, 'admin'));

    $this->post("/admin/staff/{$cashier->id}/password", ['password' => 'short'])->assertSessionHasErrors('password');
});

// ------------------------------------------------------- disabling and enabling

it('disables a person completely and at once', function () {
    $school = stfSchool();
    $cashier = stfUser($school, 'cashier');
    $oldPassword = $cashier->password;
    $cashier->createToken('phone');
    stfSession($cashier, 'session-1');
    $this->actingAs(stfUser($school, 'admin'));

    $this->post("/admin/staff/{$cashier->id}/disable")->assertRedirect();

    $cashier->refresh();

    expect($cashier->roles)->toHaveCount(0)
        ->and($cashier->password)->not->toBe($oldPassword)
        ->and($cashier->tokens()->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $cashier->id)->count())->toBe(0);

    $this->actingAs($cashier)->get('/till')->assertForbidden();
});

it('never lets you disable yourself', function () {
    $school = stfSchool();
    $admin = stfUser($school, 'admin');
    $this->actingAs($admin);

    $this->post("/admin/staff/{$admin->id}/disable")->assertSessionHasErrors('staff');

    expect($admin->refresh()->hasRole('admin'))->toBeTrue();
});

it('enables a disabled person only with a role and a new password', function () {
    $school = stfSchool();
    $cashier = stfUser($school, 'cashier');
    $cashier->syncRoles([]);
    $this->actingAs(stfUser($school, 'admin'));

    $this->post("/admin/staff/{$cashier->id}/enable", ['role' => 'cashier', 'password' => 'short'])->assertSessionHasErrors('password');
    expect($cashier->refresh()->roles)->toHaveCount(0);

    $this->post("/admin/staff/{$cashier->id}/enable", ['role' => 'cashier', 'password' => 'a-fresh-password-1'])->assertRedirect();

    expect($cashier->refresh()->hasRole('cashier'))->toBeTrue()
        ->and(Hash::check('a-fresh-password-1', $cashier->password))->toBeTrue();

    $this->actingAs($cashier)->get('/till')->assertOk();
});

it('refuses to enable someone who is already active', function () {
    $school = stfSchool();
    $cashier = stfUser($school, 'cashier');
    $this->actingAs(stfUser($school, 'admin'));

    $this->post("/admin/staff/{$cashier->id}/enable", ['role' => 'manager', 'password' => 'a-fresh-password-1'])
        ->assertSessionHasErrors('staff');

    expect($cashier->refresh()->hasRole('cashier'))->toBeTrue();
});

// ------------------------------------------------------------------ boundaries

it('cannot touch people of another school, parents or super-admins', function () {
    $school = stfSchool();
    $admin = stfUser($school, 'admin');
    $foreign = stfUser(stfSchool('FOREIGNSTF'), 'cashier');
    $parent = stfUser($school, 'parent');

    Role::findOrCreate('super-admin', 'web');
    $super = User::factory()->create(['school_id' => null]);
    $super->assignRole('super-admin');

    $this->actingAs($admin);

    foreach ([$foreign, $parent, $super] as $target) {
        $this->post("/admin/staff/{$target->id}/password", ['password' => 'brand-new-password'])->assertNotFound();
        $this->post("/admin/staff/{$target->id}/disable")->assertNotFound();
        $this->post("/admin/staff/{$target->id}/role", ['role' => 'manager'])->assertNotFound();
    }

    expect($foreign->refresh()->hasRole('cashier'))->toBeTrue()
        ->and($super->refresh()->hasRole('super-admin'))->toBeTrue();
});

it('keeps managers and cashiers out of the staff page', function () {
    $school = stfSchool();

    $this->actingAs(stfUser($school, 'manager'))->get('/admin/staff')->assertForbidden();
    $this->actingAs(stfUser($school, 'cashier'))->get('/admin/staff')->assertForbidden();
});

it('does not let a super-admin without a school manage staff', function () {
    Role::findOrCreate('super-admin', 'web');
    $super = User::factory()->create(['school_id' => null]);
    $super->assignRole('super-admin');

    $this->actingAs($super)->get('/admin/staff')->assertForbidden();
});

// -------------------------------------------------------------- install commands

it('creates the roles and the first super-admin', function () {
    $this->artisan('canteen:install', ['--name' => 'Boss', '--email' => 'boss@example.test'])
        ->expectsQuestion('Password (at least 12 characters)', 'a-long-secret-1')
        ->expectsQuestion('Repeat the password', 'a-long-secret-1')
        ->assertExitCode(0);

    $boss = User::where('email', 'boss@example.test')->first();

    expect(Role::pluck('name')->sort()->values()->all())->toBe(['admin', 'cashier', 'manager', 'parent', 'super-admin'])
        ->and($boss->hasRole('super-admin'))->toBeTrue()
        ->and($boss->school_id)->toBeNull()
        ->and(Hash::check('a-long-secret-1', $boss->password))->toBeTrue();

    // running it again changes nothing and asks nothing
    $this->artisan('canteen:install')->assertExitCode(0);
    expect(User::role('super-admin')->count())->toBe(1);
});

it('refuses a weak or mistyped super-admin password', function () {
    $this->artisan('canteen:install', ['--name' => 'Boss', '--email' => 'boss@example.test'])
        ->expectsQuestion('Password (at least 12 characters)', 'short')
        ->assertExitCode(1);

    $this->artisan('canteen:install', ['--name' => 'Boss', '--email' => 'boss@example.test'])
        ->expectsQuestion('Password (at least 12 characters)', 'a-long-secret-1')
        ->expectsQuestion('Repeat the password', 'a-different-one-1')
        ->assertExitCode(1);

    expect(User::where('email', 'boss@example.test')->exists())->toBeFalse();
});

it('creates a school', function () {
    $this->artisan('canteen:create-school', ['code' => 'abc', 'name' => 'ABC School', '--currency' => 'khr', '--sis-branch' => '5'])
        ->assertExitCode(0);

    $school = School::where('code', 'ABC')->first();

    expect($school->name)->toBe('ABC School')
        ->and($school->currency)->toBe('KHR')
        ->and($school->timezone)->toBe('Asia/Phnom_Penh')
        ->and((int) $school->sis_branch_id)->toBe(5);
});

it('refuses a bad school: currency, timezone, duplicate code, shared SIS branch', function () {
    School::create(['name' => 'Taken', 'code' => 'TAKEN', 'sis_branch_id' => 9]);

    $this->artisan('canteen:create-school', ['code' => 'NEW1', 'name' => 'N', '--currency' => 'EUR'])->assertExitCode(1);
    $this->artisan('canteen:create-school', ['code' => 'NEW1', 'name' => 'N', '--timezone' => 'Nowhere/Land'])->assertExitCode(1);
    $this->artisan('canteen:create-school', ['code' => 'taken', 'name' => 'N'])->assertExitCode(1);
    $this->artisan('canteen:create-school', ['code' => 'NEW1', 'name' => 'N', '--sis-branch' => '9'])->assertExitCode(1);
    $this->artisan('canteen:create-school', ['code' => 'bad code!', 'name' => 'N'])->assertExitCode(1);

    expect(School::where('code', 'NEW1')->exists())->toBeFalse();
});

it('creates the admin of a school', function () {
    $school = School::create(['name' => 'ABC School', 'code' => 'ABC']);

    $this->artisan('canteen:create-admin', ['school' => 'abc', 'email' => 'head@example.test', '--name' => 'Head'])
        ->expectsQuestion('Password (at least 12 characters)', 'a-long-secret-1')
        ->expectsQuestion('Repeat the password', 'a-long-secret-1')
        ->assertExitCode(0);

    $head = User::where('email', 'head@example.test')->first();

    expect($head->hasRole('admin'))->toBeTrue()
        ->and($head->school_id)->toEqual($school->id);
});

it('refuses an admin for an unknown school or a used email', function () {
    School::create(['name' => 'ABC School', 'code' => 'ABC']);
    $existing = User::factory()->create();

    $this->artisan('canteen:create-admin', ['school' => 'NOPE', 'email' => 'a@example.test', '--name' => 'A'])->assertExitCode(1);
    $this->artisan('canteen:create-admin', ['school' => 'ABC', 'email' => $existing->email, '--name' => 'A'])->assertExitCode(1);
});

// ------------------------------------------------------------ demo data guard

it('refuses to seed demo data in production', function () {
    app()->detectEnvironment(fn() => 'production');

    try {
        expect(fn() => (new DemoSeeder())->run())->toThrow(RuntimeException::class);
        expect(fn() => (new DemoCanteenSeeder())->run())->toThrow(RuntimeException::class);
        expect(fn() => (new DemoRulesSeeder())->run())->toThrow(RuntimeException::class);
    } finally {
        app()->detectEnvironment(fn() => 'testing');
    }
});
