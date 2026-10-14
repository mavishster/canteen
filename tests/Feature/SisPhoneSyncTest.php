<?php

use App\Models\GuardianLink;
use App\Models\LedgerEntry;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\Notifications\FakeSms;
use App\Services\Sis\FakeSisIdentity;
use App\Services\Sis\SisDatabaseClient;
use App\Services\Sis\SisDatabaseParentClient;
use App\Services\Sis\SisParent;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Schema;

// ------------------------------------------------------------ the cleanup

it('cleans common ways of writing a Cambodian number', function (string $raw, string $expected) {
    expect(PhoneNumber::normalize($raw))->toBe($expected);
})->with([
    ['012 345 678', '+85512345678'],
    ['012345678', '+85512345678'],
    ['0123456789', '+855123456789'],
    ['+855 12 345 678', '+85512345678'],
    ['855 12 345 678', '+85512345678'],
    ['00855 12 345 678', '+85512345678'],
    ['(012) 345-678', '+85512345678'],
    ['  +855-12-345-678  ', '+85512345678'],
    ['+66 81 234 5678', '+66812345678'],
]);

it('drops anything it cannot be sure about', function (?string $raw) {
    expect(PhoneNumber::normalize($raw))->toBeNull();
})->with([
    [null],
    [''],
    ['   '],
    ['1'],
    ['123'],
    ['N/A'],
    ['+'],
    ['012 34'],
    ['+855 1'],
    ['12345678'],          // no country code and no leading 0: not safe to guess
    ['0123 4567'],         // too short for Cambodia
    ['+1234567890123456'], // too long
]);

// ----------------------------------------------- copying it at login (fake SIS)

/** @return array{0: School, 1: FakeSisIdentity, 2: string, 3: string} */
function phoneSetup(?string $phone = '+85512345678'): array
{
    static $n = 0;
    $n++;

    $branch = 700 + $n;
    $school = School::create(['name' => "Phone School {$n}", 'code' => "PH{$n}", 'sis_branch_id' => $branch]);
    Student::create(['school_id' => $school->id, 'student_code' => "8{$n}01", 'name' => "Phone{$n} Kid"]);

    $parentId = (string) (1200 + $n);
    $fake = new FakeSisIdentity(
        tokens: ["phone-token-{$n}" => $parentId],
        parents: [new SisParent($parentId, 'Mum Sok', true, $branch, $phone)],
        links: [$parentId => ["8{$n}01"]],
    );

    config(['sis.driver' => 'fake']);
    app()->instance(FakeSisIdentity::class, $fake);

    return [$school, $fake, "phone-token-{$n}", $parentId];
}

it('copies the SIS phone number to the parent at login when the sync is on', function () {
    config(['sis.sync_phone' => true]);
    [, , $token, $parentId] = phoneSetup('+85512345678');

    $this->postJson('/api/parent/login-sis', ['sis_token' => $token])->assertOk();

    expect(User::where('sis_user_id', $parentId)->value('phone'))->toBe('+85512345678');
});

it('leaves the phone number alone when the sync is off', function () {
    config(['sis.sync_phone' => false]);
    [, , $token, $parentId] = phoneSetup('+85512345678');

    $this->postJson('/api/parent/login-sis', ['sis_token' => $token])->assertOk();

    $parent = User::where('sis_user_id', $parentId)->first();
    expect($parent->phone)->toBeNull();

    // a number set by hand survives later refreshes
    $parent->forceFill(['phone' => '+85599999999'])->save();
    $this->travel(11)->minutes();
    app(\App\Services\Sis\SisParentAccounts::class)->refresh($parent->refresh());

    expect($parent->refresh()->phone)->toBe('+85599999999');
});

it('follows the SIS when the number changes or is removed', function () {
    config(['sis.sync_phone' => true]);
    [, $fake, $token, $parentId] = phoneSetup('+85512345678');
    $this->postJson('/api/parent/login-sis', ['sis_token' => $token])->assertOk();
    $parent = User::where('sis_user_id', $parentId)->first();

    $fake->setPhone($parentId, '+85598765432');
    $this->travel(11)->minutes();
    app(\App\Services\Sis\SisParentAccounts::class)->refresh($parent->refresh());
    expect($parent->refresh()->phone)->toBe('+85598765432');

    $fake->setPhone($parentId, null);
    $this->travel(22)->minutes();
    app(\App\Services\Sis\SisParentAccounts::class)->refresh($parent->refresh());
    expect($parent->refresh()->phone)->toBeNull();
});

it('sends SMS to the number that came from the SIS', function () {
    config(['sis.sync_phone' => true, 'messaging.push' => 'fake', 'messaging.sms' => 'fake']);
    $sms = new FakeSms();
    app()->instance(FakeSms::class, $sms);

    [$school, , $token] = phoneSetup('+85512345678');
    $this->postJson('/api/parent/login-sis', ['sis_token' => $token])->assertOk();

    $kid = Student::withoutGlobalScopes()->where('school_id', $school->id)->first();
    app(LedgerService::class)->topUp($kid->account, 500, 'phone-topup-1');

    expect($sms->sent)->toHaveCount(1)
        ->and($sms->sent[0]['phone'])->toBe('+85512345678')
        ->and($sms->sent[0]['text'])->toContain('$5.00');
});

// ----------------------------------------------- the real database adapter

/** @param array<int, array<string, mixed>> $users */
function phoneSisDatabase(array $users, bool $withPhoneColumn = true): SisDatabaseParentClient
{
    config(['sis.database' => ['driver' => 'sqlite', 'database' => ':memory:']]);
    $sis = new SisDatabaseClient();
    $db = $sis->connection();

    Schema::connection('sis')->create('users', function ($t) use ($withPhoneColumn) {
        $t->increments('id');
        $t->string('name');
        $t->string('user_type')->nullable();
        $t->integer('user_status')->default(1);
        $t->integer('branch_id')->nullable();

        if ($withPhoneColumn) {
            $t->string('mobile_phone')->nullable();
        }
    });

    $db->table('users')->insert($users);

    return new SisDatabaseParentClient($sis);
}

it('reads and cleans the phone number when the sync is on', function () {
    config(['sis.sync_phone' => true]);
    $client = phoneSisDatabase([
        ['id' => 10, 'name' => 'Mum', 'user_type' => 'parent', 'user_status' => 1, 'branch_id' => 3, 'mobile_phone' => '012 345 678'],
        ['id' => 11, 'name' => 'Dad', 'user_type' => 'parent', 'user_status' => 1, 'branch_id' => 3, 'mobile_phone' => 'N/A'],
        ['id' => 12, 'name' => 'Nan', 'user_type' => 'parent', 'user_status' => 1, 'branch_id' => 3, 'mobile_phone' => null],
    ]);

    expect($client->parent('10')->phone)->toBe('+85512345678')
        ->and($client->parent('11')->phone)->toBeNull()
        ->and($client->parent('12')->phone)->toBeNull();
});

it('does not even touch the phone column when the sync is off', function () {
    config(['sis.sync_phone' => false]);
    // this SIS table has no mobile_phone column at all: a query that asked for it would fail
    $client = phoneSisDatabase([
        ['id' => 10, 'name' => 'Mum', 'user_type' => 'parent', 'user_status' => 1, 'branch_id' => 3],
    ], withPhoneColumn: false);

    $parent = $client->parent('10');

    expect($parent->name)->toBe('Mum')->and($parent->phone)->toBeNull();
});

it('counts usable phone numbers without printing any of them', function () {
    $school = School::create(['name' => 'Check School', 'code' => 'CHK', 'sis_branch_id' => 3]);
    config(['sis.database' => ['driver' => 'sqlite', 'database' => ':memory:']]);
    $sis = new SisDatabaseClient();
    app()->instance(SisDatabaseClient::class, $sis);
    $db = $sis->connection();

    Schema::connection('sis')->create('users', function ($t) {
        $t->increments('id');
        $t->string('name');
        $t->string('user_type')->nullable();
        $t->integer('user_status')->default(1);
        $t->integer('branch_id')->nullable();
        $t->string('mobile_phone')->nullable();
    });

    $db->table('users')->insert([
        ['name' => 'A', 'user_type' => 'parent', 'user_status' => 1, 'branch_id' => 3, 'mobile_phone' => '012 345 678'],
        ['name' => 'B', 'user_type' => 'parent', 'user_status' => 1, 'branch_id' => 3, 'mobile_phone' => '+855 98 765 432'],
        ['name' => 'C', 'user_type' => 'parent', 'user_status' => 1, 'branch_id' => 3, 'mobile_phone' => ''],
        ['name' => 'D', 'user_type' => 'parent', 'user_status' => 1, 'branch_id' => 3, 'mobile_phone' => 'call office 77'],
        ['name' => 'E', 'user_type' => 'student', 'user_status' => 1, 'branch_id' => 3, 'mobile_phone' => '012 345 678'],   // not a parent
        ['name' => 'F', 'user_type' => 'parent', 'user_status' => 1, 'branch_id' => 9, 'mobile_phone' => '012 345 678'],   // other branch
    ]);

    $this->artisan('sis:check-phones', ['school' => 'CHK'])
        ->expectsOutputToContain('valid: 2')
        ->expectsOutputToContain('empty: 1')
        ->expectsOutputToContain('invalid: 1')
        ->expectsOutputToContain('shape aaaa aaaaaa 99')   // the shape, never the real text
        ->assertExitCode(0);
});
