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
    $this->get('/manager')->assertRedirect('/manager/reports/daily');
    $this->get('/admin')->assertForbidden();
});

it('lets an admin open every page', function () {
    $this->actingAs(userWithRole('admin'));

    $this->get('/till')->assertOk();
    $this->get('/manager')->assertRedirect('/manager/reports/daily');
    $this->get('/admin')->assertRedirect('/admin/students');
});

it('blocks a user with no role from the role pages', function () {
    $school = School::firstOrCreate(['code' => 'AUTH'], ['name' => 'Auth School']);
    $this->actingAs(User::factory()->create(['school_id' => $school->id]));

    $this->get('/')->assertOk();
    $this->get('/till')->assertForbidden();
});
