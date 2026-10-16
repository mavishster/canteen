<?php

use App\Models\School;
use App\Models\User;
use Spatie\Permission\Models\Role;

function readyPrepare(): void
{
    foreach (['super-admin', 'admin', 'manager', 'cashier', 'parent'] as $role) {
        Role::findOrCreate($role, 'web');
    }

    $super = User::factory()->create(['email' => 'boss@real.example', 'school_id' => null]);
    $super->assignRole('super-admin');

    $school = School::create([
        'name' => 'Real School',
        'code' => 'REAL',
        'sis_branch_id' => 1,
        'settings' => ['limits' => ['weekly' => [[1, 12, 5000]]]],
    ]);

    $admin = User::factory()->create(['email' => 'head@real.example', 'school_id' => $school->id]);
    $admin->assignRole('admin');
}

/** A production setup that should pass: every value is safe. */
function readySafeProduction(): void
{
    readyPrepare();

    config([
        'app.env' => 'production',
        'app.debug' => false,
        'app.url' => 'https://canteen.example.edu',
        'app.key' => 'base64:' . str_repeat('a', 43) . '=',
        'payments.default' => 'aba',
        'payments.aba.merchant_id' => 'M1',
        'payments.aba.api_key' => 'K1',
        'payments.aba.base_url' => 'https://checkout.example',
        'messaging.push' => 'none',
        'messaging.sms' => 'none',
        'sis.driver' => 'database',
        'sis.api.base_url' => 'https://sis.example.edu',
        'sis.database.database' => 'sis_db',
        'sis.database.username' => 'canteen_ro',
        'sis.allow_local_parent_login' => false,
        'sis.sync_phone' => true,
        'queue.default' => 'database',
    ]);
}

it('passes a safe production setup, with warnings only where a person must act', function () {
    readySafeProduction();

    $this->artisan('canteen:check')->assertExitCode(0)
        ->expectsOutputToContain('No blocking problems');
});

it('blocks go-live on the dangerous settings', function () {
    readySafeProduction();
    config([
        'app.debug' => true,
        'payments.default' => 'fake',
        'messaging.sms' => 'log',
        'sis.allow_local_parent_login' => true,
        'queue.default' => 'sync',
    ]);

    $this->artisan('canteen:check')
        ->assertExitCode(1)
        ->expectsOutputToContain('Debug mode is on')
        ->expectsOutputToContain('fake payment gateway')
        ->expectsOutputToContain("'log' driver")
        ->expectsOutputToContain('email and password parent login is still on')
        ->expectsOutputToContain('queue is "sync"');
});

it('blocks go-live when demo accounts remain or the super-admin is missing', function () {
    readySafeProduction();
    User::factory()->create(['email' => 'cashier@pilot.test']);
    User::role('super-admin')->get()->each->removeRole('super-admin');

    $this->artisan('canteen:check')
        ->assertExitCode(1)
        ->expectsOutputToContain('Demo account')
        ->expectsOutputToContain('No super-admin exists');
});

it('lists results as JSON for a monitoring tool', function () {
    readySafeProduction();

    $output = \Illuminate\Support\Facades\Artisan::call('canteen:check', ['--json' => true]);
    $json = json_decode(\Illuminate\Support\Facades\Artisan::output(), true);

    expect($output)->toBe(0)
        ->and($json)->toBeArray()
        ->and(collect($json)->pluck('level')->unique()->sort()->values()->all())->not->toContain('FAIL');
});
