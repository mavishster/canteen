<?php

use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;

/** Everything a real server needs in the database: roles, a super-admin, a linked school with caps, a school admin. */
function rdyData(): void
{
    foreach (['super-admin', 'admin', 'manager', 'cashier', 'parent'] as $role) {
        Role::findOrCreate($role, 'web');
    }

    User::factory()->create(['email' => 'boss@real.example', 'school_id' => null])->assignRole('super-admin');

    $school = School::create([
        'name' => 'Real School',
        'code' => 'REAL',
        'sis_branch_id' => 1,
        'settings' => ['limits' => ['weekly' => [[1, 12, 5000]]]],
    ]);

    User::factory()->create(['email' => 'head@real.example', 'school_id' => $school->id])->assignRole('admin');
}

/** Safe settings for a server (the database is separate). */
function rdyConfig(array $override = []): void
{
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

    config($override);
}

function rdyLevels(): array
{
    Artisan::call('canteen:check', ['--json' => true]);

    return collect(json_decode(Artisan::output(), true))->mapWithKeys(fn ($r) => [$r['area'] => $r['level']])->all();
}

it('reports a brand-new server with no roles instead of crashing', function () {
    rdyConfig();   // no roles, no users, no schools in the database

    $this->artisan('canteen:check')
        ->assertExitCode(1)
        ->expectsOutputToContain('Missing roles')
        ->expectsOutputToContain('No super-admin exists')
        ->expectsOutputToContain('No school exists yet');
});

it('does not pass on the ABA driver while it is only a stub', function () {
    rdyData();
    rdyConfig();

    $this->artisan('canteen:check')
        ->assertExitCode(1)
        ->expectsOutputToContain('ABA PayWay driver is not written yet');

    expect(rdyLevels()['ABA driver'])->toBe('FAIL');
});

it('only warns about the stub driver on a development computer', function () {
    rdyData();
    rdyConfig(['app.env' => 'local']);

    expect(rdyLevels()['ABA driver'])->toBe('WARN');
});

it('does not fail a development computer for production-only settings', function () {
    rdyData();
    rdyConfig([
        'app.env' => 'local',
        'app.debug' => true,
        'app.url' => 'http://localhost:8000',
        'payments.default' => 'fake',
        'messaging.push' => 'log',
        'messaging.sms' => 'log',
        'sis.api.base_url' => null,
        'sis.allow_local_parent_login' => true,
        'queue.default' => 'sync',
    ]);
    User::factory()->create(['email' => 'cashier@pilot.test']);

    $levels = rdyLevels();

    expect($levels['App debug'])->toBe('WARN')
        ->and($levels['Environment'])->toBe('WARN')
        ->and($levels['SIS API'])->toBe('WARN')
        ->and($levels['Payments'])->toBe('WARN')
        ->and($levels['Demo accounts'])->toBe('WARN')
        ->and($levels['Queue'])->toBe('WARN')
        ->and($levels)->not->toContain('FAIL');

    $this->artisan('canteen:check')->assertExitCode(0);
});

it('fails the same settings in production', function () {
    rdyData();
    rdyConfig([
        'app.debug' => true,
        'app.url' => 'http://canteen.example.edu',
        'sis.api.base_url' => 'http://sis.example.edu',
        'sis.allow_local_parent_login' => true,
        'queue.default' => 'sync',
    ]);

    $levels = rdyLevels();

    expect($levels['App debug'])->toBe('FAIL')
        ->and($levels['HTTPS'])->toBe('FAIL')
        ->and($levels['SIS API'])->toBe('FAIL')
        ->and($levels['Parent login'])->toBe('FAIL')
        ->and($levels['Queue'])->toBe('FAIL')
        ->and($levels['Environment'])->toBe('OK');
});

it('says the SIS API address is not https in one clear line', function () {
    rdyData();
    rdyConfig(['sis.api.base_url' => 'http://sis.example.edu']);

    Artisan::call('canteen:check', ['--json' => true]);
    $lines = collect(json_decode(Artisan::output(), true))->where('area', 'SIS API');

    expect($lines)->toHaveCount(1)
        ->and($lines->first()['level'])->toBe('FAIL')
        ->and($lines->first()['message'])->toContain('not https');
});

it('reports an empty database as needing migrations', function () {
    rdyConfig();
    \Illuminate\Support\Facades\Schema::dropIfExists('migrations');

    $this->artisan('canteen:check')
        ->assertExitCode(1)
        ->expectsOutputToContain('The database is empty');
});
