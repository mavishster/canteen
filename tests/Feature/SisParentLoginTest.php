<?php

use App\Models\GuardianLink;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Services\Sis\FakeSisIdentity;
use App\Services\Sis\SisDatabaseClient;
use App\Services\Sis\SisDatabaseParentClient;
use App\Services\Sis\SisHttpAuthClient;
use App\Services\Sis\SisParent;
use App\Services\Sis\SisUnavailableException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

/** @return array{0: School, 1: FakeSisIdentity, 2: string, 3: string} school, fake SIS, SIS token, SIS parent id */
function ploginSetup(array $students = ['7001', '7002']): array
{
    static $n = 0;
    $n++;

    $branch = 500 + $n;
    $school = School::create(['name' => "Login School {$n}", 'code' => "LOG{$n}", 'sis_branch_id' => $branch]);

    foreach ($students as $code) {
        Student::create(['school_id' => $school->id, 'student_code' => $code, 'name' => "Kid {$code}"]);
    }

    $parentId = (string) (900 + $n);
    $fake = new FakeSisIdentity(
        tokens: ["token-{$n}" => $parentId],
        parents: [new SisParent($parentId, 'Mum Sok', true, $branch)],
        links: [$parentId => $students],
    );

    config(['sis.driver' => 'fake']);
    app()->instance(FakeSisIdentity::class, $fake);

    return [$school, $fake, "token-{$n}", $parentId];
}

function ploginChildren($test, string $token)
{
    app('auth')->forgetGuards();   // a real server starts every request fresh

    return $test->withToken($token)->getJson('/api/parent/children');
}

function ploginCodes($response): array
{
    return collect($response->json('data'))->pluck('student_code')->sort()->values()->all();
}

// ------------------------------------------------------------------ login

it('logs a parent in with a valid SIS token, creates the account and links the children', function () {
    [$school, , $token, $parentId] = ploginSetup();

    $response = $this->postJson('/api/parent/login-sis', ['sis_token' => $token, 'device_name' => 'phone'])
        ->assertOk()
        ->assertJsonPath('children', 2);

    $parent = User::where('sis_user_id', $parentId)->first();

    expect($parent)->not->toBeNull()
        ->and($parent->hasRole('parent'))->toBeTrue()
        ->and($parent->school_id)->toEqual($school->id)
        ->and($parent->email)->toBe("sis-parent-{$parentId}@sis.invalid")
        ->and(GuardianLink::withoutGlobalScopes()->where('user_id', $parent->id)->count())->toBe(2);

    $children = ploginChildren($this, $response->json('token'))->assertOk();

    expect(ploginCodes($children))->toBe(['7001', '7002']);
});

it('creates the local account only once', function () {
    [, , $token, $parentId] = ploginSetup();

    $this->postJson('/api/parent/login-sis', ['sis_token' => $token])->assertOk();
    $this->postJson('/api/parent/login-sis', ['sis_token' => $token])->assertOk();

    expect(User::where('sis_user_id', $parentId)->count())->toBe(1);
});

it('rejects a token the SIS does not know', function () {
    ploginSetup();

    $this->postJson('/api/parent/login-sis', ['sis_token' => 'made-up'])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'invalid_token');

    expect(User::whereNotNull('sis_user_id')->count())->toBe(0);
});

it('rejects inactive parents and SIS users who are not parents, with one message', function () {
    [, $fake, $token, $parentId] = ploginSetup();
    $fake->deactivate($parentId);

    $inactive = $this->postJson('/api/parent/login-sis', ['sis_token' => $token])->assertForbidden()->json('message');

    // A valid SIS login whose user is a student or a teacher: the parent lookup finds nothing
    $other = new FakeSisIdentity(tokens: ['staff-token' => '1'], parents: [], links: []);
    app()->instance(FakeSisIdentity::class, $other);
    $notParent = $this->postJson('/api/parent/login-sis', ['sis_token' => 'staff-token'])->assertForbidden()->json('message');

    expect($inactive)->toBe($notParent)
        ->and(User::whereNotNull('sis_user_id')->count())->toBe(0);
});

it('refuses a parent whose school is not set up in the canteen', function () {
    [$school, , $token] = ploginSetup();
    $school->update(['sis_branch_id' => null]);

    $this->postJson('/api/parent/login-sis', ['sis_token' => $token])
        ->assertForbidden()
        ->assertJsonPath('reason', 'school_unknown');
});

it('links children only inside the parent\'s own school', function () {
    [$school, , $token, $parentId] = ploginSetup(['7001']);
    $otherSchool = School::create(['name' => 'Other', 'code' => 'OTHERSCH', 'sis_branch_id' => 999]);
    Student::create(['school_id' => $otherSchool->id, 'student_code' => '7001', 'name' => 'Same id, other school']);

    $this->postJson('/api/parent/login-sis', ['sis_token' => $token])->assertOk()->assertJsonPath('children', 1);

    $link = GuardianLink::withoutGlobalScopes()->first();

    expect($link->school_id)->toEqual($school->id)
        ->and(Student::withoutGlobalScopes()->find($link->student_id)->name)->toBe('Kid 7001');
});

it('skips children the canteen does not have yet', function () {
    [, $fake, $token, $parentId] = ploginSetup(['7001']);
    $fake->setLinks($parentId, ['7001', '9999']);

    $this->postJson('/api/parent/login-sis', ['sis_token' => $token])->assertOk()->assertJsonPath('children', 1);
});

it('answers 503 for a login while the SIS is down', function () {
    [, $fake, $token] = ploginSetup();
    $fake->down = true;

    $this->postJson('/api/parent/login-sis', ['sis_token' => $token])
        ->assertStatus(503)
        ->assertJsonPath('reason', 'sis_unavailable');
});

it('needs the SIS token field', function () {
    ploginSetup();

    $this->postJson('/api/parent/login-sis', [])->assertStatus(422)->assertJsonValidationErrors('sis_token');
});

// ----------------------------------------------------- staying in step with the SIS

it('keeps using the saved links within the cache time', function () {
    [$school, $fake, $token, $parentId] = ploginSetup(['7001', '7002']);
    $access = $this->postJson('/api/parent/login-sis', ['sis_token' => $token])->json('token');

    $fake->setLinks($parentId, ['7002']);   // changed in the SIS just now

    expect(ploginCodes(ploginChildren($this, $access)->assertOk()))->toBe(['7001', '7002']);
});

it('picks up added and removed children after the cache time', function () {
    [$school, $fake, $token, $parentId] = ploginSetup(['7001', '7002']);
    Student::create(['school_id' => $school->id, 'student_code' => '7003', 'name' => 'Kid 7003']);
    $access = $this->postJson('/api/parent/login-sis', ['sis_token' => $token])->json('token');

    $fake->setLinks($parentId, ['7002', '7003']);
    $this->travel(11)->minutes();

    expect(ploginCodes(ploginChildren($this, $access)->assertOk()))->toBe(['7002', '7003']);
});

it('closes access when the SIS disables the parent', function () {
    [, $fake, $token, $parentId] = ploginSetup();
    $access = $this->postJson('/api/parent/login-sis', ['sis_token' => $token])->json('token');
    $parent = User::where('sis_user_id', $parentId)->first();

    $fake->deactivate($parentId);
    $this->travel(11)->minutes();

    ploginChildren($this, $access)->assertStatus(401)->assertJsonPath('reason', 'account_disabled');

    expect($parent->tokens()->count())->toBe(0)
        ->and(GuardianLink::withoutGlobalScopes()->where('user_id', $parent->id)->count())->toBe(0);
});

it('keeps working for a while when the SIS is down, then stops', function () {
    [, $fake, $token] = ploginSetup();
    $access = $this->postJson('/api/parent/login-sis', ['sis_token' => $token])->json('token');

    $fake->down = true;

    $this->travel(11)->minutes();
    ploginChildren($this, $access)->assertOk();                         // within the grace time

    $this->travel(70)->minutes();
    ploginChildren($this, $access)->assertStatus(503);                  // too long: fail closed
});

it('never asks the SIS about parents created by the school admin', function () {
    [$school, $fake] = ploginSetup();
    $fake->down = true;

    Role::findOrCreate('parent', 'web');
    $manual = User::factory()->create(['school_id' => $school->id]);
    $manual->assignRole('parent');
    $kid = Student::withoutGlobalScopes()->where('school_id', $school->id)->first();
    GuardianLink::create(['school_id' => $school->id, 'user_id' => $manual->id, 'student_id' => $kid->id]);

    Sanctum::actingAs($manual);

    $this->getJson('/api/parent/children')->assertOk()->assertJsonCount(1, 'data');
});

// ----------------------------------------------------------- the old password login

it('can switch off the password login from Step 15', function () {
    [$school] = ploginSetup();
    Role::findOrCreate('parent', 'web');
    $manual = User::factory()->create(['school_id' => $school->id, 'password' => Hash::make('secret-pass-1')]);
    $manual->assignRole('parent');

    $this->postJson('/api/parent/login', ['email' => $manual->email, 'password' => 'secret-pass-1'])->assertOk();

    config(['sis.allow_local_parent_login' => false]);

    $this->postJson('/api/parent/login', ['email' => $manual->email, 'password' => 'secret-pass-1'])->assertNotFound();
});

it('gives SIS parents no password anyone could use', function () {
    [, , $token, $parentId] = ploginSetup();
    $this->postJson('/api/parent/login-sis', ['sis_token' => $token])->assertOk();

    $this->postJson('/api/parent/login', ['email' => "sis-parent-{$parentId}@sis.invalid", 'password' => 'password'])
        ->assertStatus(422);
});

// ----------------------------------------------------------- the real adapters

it('asks the SIS API with the parent\'s token and reads only the id', function () {
    config(['sis.api' => ['base_url' => 'https://sis.test', 'user_path' => '/api/user', 'wrapper' => 'data', 'id_field' => 'id', 'timeout' => 5]]);
    Http::fake(['https://sis.test/api/user' => Http::response(['data' => ['id' => 42, 'name' => 'Ignored', 'password' => 'never read']], 200)]);

    $user = app(SisHttpAuthClient::class)->userForToken('parent-sis-token');

    expect($user->id)->toBe('42');

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer parent-sis-token'));
});

it('reads the id from the top level when there is no wrapper', function () {
    config(['sis.api' => ['base_url' => 'https://sis.test', 'user_path' => '/api/user', 'wrapper' => null, 'id_field' => 'id']]);
    Http::fake(['https://sis.test/api/user' => Http::response(['id' => 7], 200)]);

    expect(app(SisHttpAuthClient::class)->userForToken('t')->id)->toBe('7');
});

it('treats a refused token as unknown and a SIS error as unavailable', function () {
    config(['sis.api' => ['base_url' => 'https://sis.test', 'user_path' => '/api/user']]);

    Http::fake(['https://sis.test/api/user' => Http::response(['message' => 'Unauthenticated.'], 401)]);
    expect(app(SisHttpAuthClient::class)->userForToken('bad'))->toBeNull();

    Http::fake(['https://sis.test/api/user' => Http::response('boom', 500)]);
    expect(fn () => app(SisHttpAuthClient::class)->userForToken('t'))->toThrow(SisUnavailableException::class);
});

it('is unavailable when the SIS API is not configured', function () {
    config(['sis.api' => ['base_url' => null]]);

    expect(fn () => app(SisHttpAuthClient::class)->userForToken('t'))->toThrow(SisUnavailableException::class);
});

it('reads parents and their children from the SIS database', function () {
    config(['sis.database' => ['driver' => 'sqlite', 'database' => ':memory:']]);
    $sis = new SisDatabaseClient();
    $db = $sis->connection();

    Schema::connection('sis')->create('users', function ($t) {
        $t->increments('id');
        $t->string('name');
        $t->string('user_type')->nullable();
        $t->integer('user_status')->default(1);
        $t->integer('branch_id')->nullable();
    });
    Schema::connection('sis')->create('student_parents', function ($t) {
        $t->increments('id');
        $t->integer('student_id');
        $t->integer('parent_id');
        $t->string('relation_type')->nullable();
    });

    $db->table('users')->insert([
        ['id' => 10, 'name' => ' Mum ', 'user_type' => 'parent', 'user_status' => 1, 'branch_id' => 3],
        ['id' => 11, 'name' => 'Gone', 'user_type' => 'parent', 'user_status' => 0, 'branch_id' => 3],
        ['id' => 12, 'name' => 'Teacher', 'user_type' => 'staff', 'user_status' => 1, 'branch_id' => 3],
        ['id' => 13, 'name' => 'Pupil', 'user_type' => 'student', 'user_status' => 1, 'branch_id' => 3],
    ]);
    $db->table('student_parents')->insert([
        ['student_id' => 7001, 'parent_id' => 10, 'relation_type' => 'mother'],
        ['student_id' => 7002, 'parent_id' => 10, 'relation_type' => null],
        ['student_id' => 7003, 'parent_id' => 11, 'relation_type' => 'father'],
    ]);

    $client = new SisDatabaseParentClient($sis);

    $mum = $client->parent('10');
    expect($mum->name)->toBe('Mum')->and($mum->active)->toBeTrue()->and($mum->branchId)->toBe(3)
        ->and($client->parent('11')->active)->toBeFalse()
        ->and($client->parent('12'))->toBeNull()      // staff
        ->and($client->parent('13'))->toBeNull()      // a student
        ->and($client->parent('999'))->toBeNull();

    expect(collect($client->childrenOf('10'))->pluck('studentId')->all())->toBe(['7001', '7002']);
});
