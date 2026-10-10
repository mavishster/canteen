<?php

use App\Models\Category;
use App\Models\GuardianLink;
use App\Models\Product;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentBan;
use App\Models\Topup;
use App\Models\User;
use App\Services\CardService;
use App\Services\LedgerService;
use App\Services\Payments\FakeGateway;
use App\Services\SaleService;
use App\Services\TopUpService;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

function parentUser(School $school, string $role = 'parent'): User
{
    Role::findOrCreate($role, 'web');

    $user = User::factory()->create(['school_id' => $school->id]);
    $user->assignRole($role);

    return $user;
}

/** @return array{0: School, 1: Student, 2: User, 3: string} school, linked child, parent, card number */
function parentSetup(int $balance = 5000, array $settings = []): array
{
    static $n = 0;
    $n++;

    $school = School::create(['name' => "Parent School {$n}", 'code' => "PAR{$n}", 'settings' => $settings ?: null]);
    $student = Student::create([
        'school_id' => $school->id,
        'student_code' => "PA{$n}",
        'name' => "Parent Kid {$n}",
        'grade' => 4,
    ]);

    $uid = strtoupper(dechex(0x60000000 + $n));
    app(CardService::class)->bind($student, $uid);

    if ($balance > 0) {
        app(LedgerService::class)->topUp($student->account, $balance, "par-seed-{$n}");
    }

    $parent = parentUser($school);
    GuardianLink::create(['school_id' => $school->id, 'user_id' => $parent->id, 'student_id' => $student->id]);

    return [$school, $student, $parent, $uid];
}

// ------------------------------------------------------------------ login

it('logs a parent in and returns a token that works', function () {
    [, , $parent] = parentSetup();
    $parent->update(['password' => Hash::make('secret-pass-1')]);

    $token = $this->postJson('/api/parent/login', [
        'email' => $parent->email,
        'password' => 'secret-pass-1',
        'device_name' => 'test phone',
    ])->assertOk()->json('token');

    expect($token)->toBeString();

    $this->withToken($token)->getJson('/api/parent/children')->assertOk()->assertJsonCount(1, 'data');
});

it('refuses a wrong password, and staff accounts, with the same message', function () {
    [$school, , $parent] = parentSetup();
    $parent->update(['password' => Hash::make('secret-pass-1')]);
    $cashier = parentUser($school, 'cashier');
    $cashier->update(['password' => Hash::make('secret-pass-1')]);

    $wrong = $this->postJson('/api/parent/login', ['email' => $parent->email, 'password' => 'nope'])
        ->assertStatus(422)->json('message');
    $staff = $this->postJson('/api/parent/login', ['email' => $cashier->email, 'password' => 'secret-pass-1'])
        ->assertStatus(422)->json('message');
    $unknown = $this->postJson('/api/parent/login', ['email' => 'nobody@example.test', 'password' => 'x'])
        ->assertStatus(422)->json('message');

    expect($wrong)->toBe($staff)->and($wrong)->toBe($unknown);
});

it('deletes the token on logout', function () {
    [, , $parent] = parentSetup();
    $parent->update(['password' => Hash::make('secret-pass-1')]);

    $token = $this->postJson('/api/parent/login', ['email' => $parent->email, 'password' => 'secret-pass-1'])->json('token');
    expect($parent->tokens()->count())->toBe(1);

    $this->withToken($token)->postJson('/api/parent/logout')->assertOk();

    expect($parent->tokens()->count())->toBe(0);
});

it('answers 401 without a token and 403 for staff accounts', function () {
    [$school] = parentSetup();

    $this->getJson('/api/parent/children')->assertUnauthorized();

    Sanctum::actingAs(parentUser($school, 'cashier'));
    $this->getJson('/api/parent/children')->assertForbidden();
});

// --------------------------------------------------------------- children

it('lists only the parent\'s own children, with balance, card status and limits', function () {
    [$school, $student, $parent] = parentSetup(5000, ['limits' => ['weekly' => [[1, 12, 5000]]]]);
    Student::create(['school_id' => $school->id, 'student_code' => 'OTHER', 'name' => 'Not my child']);   // same school, not linked

    Sanctum::actingAs($parent);

    $this->getJson('/api/parent/children')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', $student->name)
        ->assertJsonPath('data.0.balance', 5000)
        ->assertJsonPath('data.0.balance_formatted', '$50.00')
        ->assertJsonPath('data.0.card.status', 'active')
        ->assertJsonPath('data.0.limits.weekly.school_max', 5000)
        ->assertJsonMissingPath('data.0.card.uid');
});

it('never shows a child that is not linked, even in the same school', function () {
    [$school, , $parent] = parentSetup();
    $stranger = Student::create(['school_id' => $school->id, 'student_code' => 'STR', 'name' => 'Stranger']);

    Sanctum::actingAs($parent);

    $this->getJson("/api/parent/children/{$stranger->id}/transactions")->assertNotFound();
    $this->getJson("/api/parent/children/{$stranger->id}/rules")->assertNotFound();
    $this->putJson("/api/parent/children/{$stranger->id}/limits", ['weekly_limit' => 5])->assertNotFound();
    $this->postJson("/api/parent/children/{$stranger->id}/card/block")->assertNotFound();
    $this->postJson("/api/parent/children/{$stranger->id}/topups", ['amount' => 5, 'currency' => 'USD'])->assertNotFound();
});

it('keeps two families apart', function () {
    [, $kidA, $parentA] = parentSetup();
    [, $kidB] = parentSetup();

    Sanctum::actingAs($parentA);

    $this->getJson("/api/parent/children/{$kidB->id}/transactions")->assertNotFound();
    $this->getJson("/api/parent/children/{$kidA->id}/transactions")->assertOk();
});

it('shows the history with what was bought', function () {
    [$school, $student, $parent, $uid] = parentSetup(5000);
    $cola = Product::create(['school_id' => $school->id, 'name' => 'Cola', 'price' => 350]);
    app(SaleService::class)->checkout($school->id, null, $uid, [['product_id' => $cola->id, 'quantity' => 2]], 'k1');

    Sanctum::actingAs($parent);
    $response = $this->getJson("/api/parent/children/{$student->id}/transactions")->assertOk();

    expect(collect($response->json('data'))->pluck('type')->all())->toBe(['purchase', 'topup'])
        ->and($response->json('data.0.amount'))->toBe(-700)
        ->and($response->json('data.0.balance_after'))->toBe(4300)
        ->and($response->json('data.0.items.0.name'))->toBe('Cola')
        ->and($response->json('data.1.items'))->toBeNull();
});

// ----------------------------------------------------------------- limits

it('lets a parent set and clear personal limits', function () {
    [, $student, $parent] = parentSetup();
    Sanctum::actingAs($parent);

    $this->putJson("/api/parent/children/{$student->id}/limits", ['daily_limit' => '2.50', 'weekly_limit' => '10'])
        ->assertOk()
        ->assertJsonPath('limits.daily.personal', 250)
        ->assertJsonPath('limits.weekly.personal', 1000);

    $this->putJson("/api/parent/children/{$student->id}/limits", ['daily_limit' => null, 'weekly_limit' => null])
        ->assertOk()
        ->assertJsonPath('limits.daily.personal', null);

    expect($student->account->refresh()->weekly_limit)->toBeNull();
});

it('refuses a limit above the school maximum', function () {
    [, $student, $parent] = parentSetup(5000, ['limits' => ['weekly' => [[1, 12, 5000]]]]);
    Sanctum::actingAs($parent);

    $this->putJson("/api/parent/children/{$student->id}/limits", ['weekly_limit' => '60'])->assertStatus(422);

    expect($student->account->refresh()->weekly_limit)->toBeNull();
});

// ------------------------------------------------------------------- bans

it('lets a parent ban and unban a product or a category', function () {
    [$school, $student, $parent] = parentSetup();
    $sweets = Category::create(['school_id' => $school->id, 'name' => 'Sweets']);
    $cola = Product::create(['school_id' => $school->id, 'name' => 'Cola', 'price' => 100, 'category_id' => $sweets->id]);
    Sanctum::actingAs($parent);

    $this->postJson("/api/parent/children/{$student->id}/bans", ['type' => 'product', 'id' => $cola->id])->assertCreated();
    $this->postJson("/api/parent/children/{$student->id}/bans", ['type' => 'category', 'id' => $sweets->id])->assertCreated();

    $rules = $this->getJson("/api/parent/children/{$student->id}/rules")->assertOk();
    expect($rules->json('bans'))->toHaveCount(2)
        ->and($rules->json('catalog.products.0.name'))->toBe('Cola');

    $banId = StudentBan::where('product_id', $cola->id)->value('id');
    $this->deleteJson("/api/parent/children/{$student->id}/bans/{$banId}")->assertOk();

    expect(StudentBan::where('student_id', $student->id)->count())->toBe(1);
});

it('cannot remove a ban that belongs to another child', function () {
    [$school, $student, $parent] = parentSetup();
    [, $otherKid] = parentSetup();
    $product = Product::create(['school_id' => $otherKid->school_id, 'name' => 'Cola', 'price' => 100]);
    $ban = StudentBan::create(['school_id' => $otherKid->school_id, 'student_id' => $otherKid->id, 'product_id' => $product->id]);

    Sanctum::actingAs($parent);

    $this->deleteJson("/api/parent/children/{$student->id}/bans/{$ban->id}")->assertNotFound();

    expect(StudentBan::count())->toBe(1);
});

// ------------------------------------------------------------------- card

it('lets a parent block and unblock the card, and the till refuses it while blocked', function () {
    [$school, $student, $parent, $uid] = parentSetup();
    Sanctum::actingAs($parent);

    $this->postJson("/api/parent/children/{$student->id}/card/block")->assertOk()->assertJsonPath('status', 'blocked');
    expect(fn () => app(CardService::class)->resolve($uid, $school->id))
        ->toThrow(\App\Exceptions\CardException::class);

    $this->postJson("/api/parent/children/{$student->id}/card/unblock")->assertOk()->assertJsonPath('status', 'active');
    expect(app(CardService::class)->resolve($uid, $school->id)->student_id)->toBe($student->id);
});

it('says so when the child has no card yet', function () {
    [$school, , $parent] = parentSetup();
    $cardless = Student::create(['school_id' => $school->id, 'student_code' => 'NC', 'name' => 'No card']);
    GuardianLink::create(['school_id' => $school->id, 'user_id' => $parent->id, 'student_id' => $cardless->id]);
    Sanctum::actingAs($parent);

    $this->postJson("/api/parent/children/{$cardless->id}/card/block")->assertStatus(422);
});

// ----------------------------------------------------------------- top-ups

it('starts a top-up and shows it as paid once the bank confirms', function () {
    [, $student, $parent] = parentSetup(5000);
    Sanctum::actingAs($parent);

    $started = $this->postJson("/api/parent/children/{$student->id}/topups", ['amount' => '5', 'currency' => 'USD'])
        ->assertCreated()
        ->assertJsonPath('status', 'pending');
    $ref = $started->json('ref');

    expect($started->json('checkout_url'))->toContain($ref)
        ->and($student->account->refresh()->balance)->toBe(5000);   // nothing credited by the app alone

    $topup = Topup::where('gateway_ref', $ref)->first();
    $fake = app(FakeGateway::class);
    $fake->simulate($topup, 'paid');
    app(TopUpService::class)->handleCallback($fake, ['ref' => $ref, 'sig' => $fake->signature($ref)]);

    $this->getJson("/api/parent/topups/{$ref}")->assertOk()->assertJsonPath('status', 'paid');

    expect($student->account->refresh()->balance)->toBe(5500);
});

it('rejects a top-up below the minimum', function () {
    [, $student, $parent] = parentSetup();
    Sanctum::actingAs($parent);

    $this->postJson("/api/parent/children/{$student->id}/topups", ['amount' => '0.50', 'currency' => 'USD'])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'below_minimum');

    expect(Topup::count())->toBe(0);
});

it('only shows a parent their own top-ups', function () {
    [, $student, $parent] = parentSetup();
    [, $otherKid, $otherParent] = parentSetup();

    Sanctum::actingAs($otherParent);
    $ref = $this->postJson("/api/parent/children/{$otherKid->id}/topups", ['amount' => '5', 'currency' => 'USD'])->json('ref');

    Sanctum::actingAs($parent);
    $this->getJson("/api/parent/topups/{$ref}")->assertNotFound();
});

// ------------------------------------------------------------- admin screen

it('lets an admin create a parent account and link children', function () {
    $school = School::create(['name' => 'S1', 'code' => 'S1']);
    $student = Student::create(['school_id' => $school->id, 'student_code' => 'A1', 'name' => 'Alice']);
    $this->actingAs(parentUser($school, 'admin'));

    $this->post('/admin/parents', ['name' => 'Mum', 'email' => 'mum@example.test', 'password' => 'long-password-1'])
        ->assertRedirect(route('admin.parents'));

    $mum = User::where('email', 'mum@example.test')->first();

    expect($mum->hasRole('parent'))->toBeTrue()
        ->and($mum->school_id)->toEqual($school->id)
        ->and(Hash::check('long-password-1', $mum->password))->toBeTrue();

    $this->post("/admin/parents/{$mum->id}/students", ['student_id' => $student->id])->assertRedirect();
    $this->post("/admin/parents/{$mum->id}/students", ['student_id' => $student->id])->assertRedirect();   // twice: still one link

    expect(GuardianLink::where('user_id', $mum->id)->count())->toBe(1);

    $this->get('/admin/parents')->assertOk()->assertSee('Mum')->assertSee('Alice');

    $link = GuardianLink::first();
    $this->post("/admin/parent-links/{$link->id}/delete")->assertRedirect();

    expect(GuardianLink::count())->toBe(0);
});

it('rejects a duplicate email or a short password', function () {
    $school = School::create(['name' => 'S1', 'code' => 'S1']);
    $existing = parentUser($school);
    $this->actingAs(parentUser($school, 'admin'));

    $this->post('/admin/parents', ['name' => 'Dup', 'email' => $existing->email, 'password' => 'long-password-1'])
        ->assertSessionHasErrors('email');
    $this->post('/admin/parents', ['name' => 'Short', 'email' => 'short@example.test', 'password' => '1234'])
        ->assertSessionHasErrors('password');
});

it('signs a parent out everywhere when the admin sets a new password', function () {
    $school = School::create(['name' => 'S1', 'code' => 'S1']);
    $mum = parentUser($school);
    $mum->createToken('phone');
    $this->actingAs(parentUser($school, 'admin'));

    $this->post("/admin/parents/{$mum->id}/password", ['password' => 'brand-new-pass'])->assertRedirect();

    expect($mum->tokens()->count())->toBe(0)
        ->and(Hash::check('brand-new-pass', $mum->refresh()->password))->toBeTrue();
});

it("cannot touch another school's parent", function () {
    $mine = School::create(['name' => 'Mine', 'code' => 'MINE']);
    $other = School::create(['name' => 'Other', 'code' => 'OTHER']);
    $theirs = parentUser($other);
    $this->actingAs(parentUser($mine, 'admin'));

    $this->post("/admin/parents/{$theirs->id}/password", ['password' => 'brand-new-pass'])->assertNotFound();
});

it('keeps cashiers out of the parents page', function () {
    $school = School::create(['name' => 'S1', 'code' => 'S1']);
    $this->actingAs(parentUser($school, 'cashier'));

    $this->get('/admin/parents')->assertForbidden();
});

// ------------------------------------------------------------- migrate
