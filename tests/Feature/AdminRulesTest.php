<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentBan;
use App\Models\User;
use Spatie\Permission\Models\Role;

function rulesAdmin(School $school, string $role = 'admin'): User
{
    Role::findOrCreate($role, 'web');

    $user = User::factory()->create(['school_id' => $school->id]);
    $user->assignRole($role);

    return $user;
}

function rulesKid(School $school, ?int $grade = 4): Student
{
    static $n = 0;
    $n++;

    return Student::create([
        'school_id' => $school->id,
        'student_code' => "RA{$n}",
        'name' => "Kid {$n}",
        'grade' => $grade,
    ]);
}

// ------------------------------------------------------- student limits

it('saves personal limits in minor units', function () {
    $school = School::create(['name' => 'S1', 'code' => 'S1']);
    $student = rulesKid($school);
    $this->actingAs(rulesAdmin($school));

    $this->post("/admin/students/{$student->id}/limits", ['daily_limit' => '2.50', 'weekly_limit' => '10'])
        ->assertRedirect(route('admin.students.rules', $student));

    $account = $student->account->refresh();

    expect($account->daily_limit)->toBe(250)
        ->and($account->weekly_limit)->toBe(1000);
});

it('clears personal limits when the boxes are left empty', function () {
    $school = School::create(['name' => 'S1', 'code' => 'S1']);
    $student = rulesKid($school);
    $this->actingAs(rulesAdmin($school));

    $this->post("/admin/students/{$student->id}/limits", ['daily_limit' => '2.50', 'weekly_limit' => '10']);
    $this->post("/admin/students/{$student->id}/limits", ['daily_limit' => '', 'weekly_limit' => '']);

    $account = $student->account->refresh();

    expect($account->daily_limit)->toBeNull()
        ->and($account->weekly_limit)->toBeNull();
});

it('refuses a personal limit above the school maximum', function () {
    $school = School::create([
        'name' => 'S1',
        'code' => 'S1',
        'settings' => ['limits' => ['weekly' => [[1, 12, 5000]]]],
    ]);
    $student = rulesKid($school);
    $this->actingAs(rulesAdmin($school));

    $this->post("/admin/students/{$student->id}/limits", ['weekly_limit' => '60'])
        ->assertSessionHasErrors('limits');

    expect($student->account->refresh()->weekly_limit)->toBeNull();
});

// ------------------------------------------------------------------ bans

it('adds and removes product and category bans', function () {
    $school = School::create(['name' => 'S1', 'code' => 'S1']);
    $student = rulesKid($school);
    $sweets = Category::create(['school_id' => $school->id, 'name' => 'Sweets']);
    $cola = Product::create(['school_id' => $school->id, 'name' => 'Cola', 'price' => 100]);
    $this->actingAs(rulesAdmin($school));

    $this->post("/admin/students/{$student->id}/bans", ['ban' => "product:{$cola->id}"])->assertRedirect();
    $this->post("/admin/students/{$student->id}/bans", ['ban' => "category:{$sweets->id}"])->assertRedirect();

    expect(StudentBan::where('student_id', $student->id)->count())->toBe(2);

    $this->get("/admin/students/{$student->id}/rules")->assertOk()->assertSee('Cola')->assertSee('Sweets');

    $ban = StudentBan::where('product_id', $cola->id)->first();
    $this->post("/admin/bans/{$ban->id}/delete")->assertRedirect();

    expect(StudentBan::where('student_id', $student->id)->count())->toBe(1);
});

it('does not add the same ban twice', function () {
    $school = School::create(['name' => 'S1', 'code' => 'S1']);
    $student = rulesKid($school);
    $cola = Product::create(['school_id' => $school->id, 'name' => 'Cola', 'price' => 100]);
    $this->actingAs(rulesAdmin($school));

    $this->post("/admin/students/{$student->id}/bans", ['ban' => "product:{$cola->id}"]);
    $this->post("/admin/students/{$student->id}/bans", ['ban' => "product:{$cola->id}"]);

    expect(StudentBan::where('student_id', $student->id)->count())->toBe(1);
});

it("cannot open another school's student rules", function () {
    $mine = School::create(['name' => 'Mine', 'code' => 'MINE']);
    $other = School::create(['name' => 'Other', 'code' => 'OTHER']);
    $theirs = rulesKid($other);

    $this->actingAs(rulesAdmin($mine));

    $this->get("/admin/students/{$theirs->id}/rules")->assertNotFound();
    $this->post("/admin/students/{$theirs->id}/limits", ['weekly_limit' => '5'])->assertNotFound();
});

it('keeps cashiers out of the rules screens', function () {
    $school = School::create(['name' => 'S1', 'code' => 'S1']);
    $student = rulesKid($school);
    $this->actingAs(rulesAdmin($school, 'cashier'));

    $this->get("/admin/students/{$student->id}/rules")->assertForbidden();
    $this->get('/admin/settings')->assertForbidden();
});

// -------------------------------------------------------- school settings

it('shows the settings page', function () {
    $school = School::create(['name' => 'S1', 'code' => 'S1']);
    $this->actingAs(rulesAdmin($school));

    $this->get('/admin/settings')->assertOk()->assertSee('Buying hours');
});

it('saves caps by grade band and buying hours', function () {
    $school = School::create(['name' => 'S1', 'code' => 'S1']);
    $this->actingAs(rulesAdmin($school));

    $this->post('/admin/settings', [
        'daily' => [null, null, null, null],
        'weekly' => ['30', '40', '50', '60'],
        'restrict_hours' => '1',
        'days' => ['1', '2', '3', '4', '5'],
        'windows' => [
            ['from' => '07:00', 'to' => '09:00'],
            ['from' => '11:30', 'to' => '13:30'],
            ['from' => '', 'to' => ''],
        ],
    ])->assertRedirect(route('admin.settings'));

    $settings = $school->refresh()->settings;

    expect($settings['limits']['weekly'])->toBe([[1, 3, 3000], [4, 6, 4000], [7, 9, 5000], [10, 12, 6000]])
        ->and($settings['limits']['daily'])->toBe([])
        ->and($settings['buying_hours'])->toBe([
            'days' => [1, 2, 3, 4, 5],
            'windows' => [['07:00', '09:00'], ['11:30', '13:30']],
        ]);
});

it('clears the buying hours when the switch is off and keeps blank caps empty', function () {
    $school = School::create([
        'name' => 'S1',
        'code' => 'S1',
        'settings' => ['buying_hours' => ['days' => [1], 'windows' => []]],
    ]);
    $this->actingAs(rulesAdmin($school));

    $this->post('/admin/settings', ['weekly' => ['', '', '', '']])->assertRedirect();

    $school->refresh();

    expect($school->buyingHours())->toBeNull()
        ->and($school->capForGrade('weekly', 4))->toBeNull();
});

it('rejects a buying window that ends before it starts', function () {
    $school = School::create(['name' => 'S1', 'code' => 'S1']);
    $this->actingAs(rulesAdmin($school));

    $this->post('/admin/settings', [
        'restrict_hours' => '1',
        'days' => ['1'],
        'windows' => [['from' => '13:00', 'to' => '11:00']],
    ])->assertSessionHasErrors('windows');

    expect($school->refresh()->buyingHours())->toBeNull();
});

it('requires at least one day when buying hours are on', function () {
    $school = School::create(['name' => 'S1', 'code' => 'S1']);
    $this->actingAs(rulesAdmin($school));

    $this->post('/admin/settings', ['restrict_hours' => '1'])->assertSessionHasErrors('days');
});

it('stores riel caps as whole riel', function () {
    $school = School::create(['name' => 'S1', 'code' => 'S1', 'currency' => 'KHR']);
    $this->actingAs(rulesAdmin($school));

    $this->post('/admin/settings', ['weekly' => ['120000', '', '', '']]);

    expect($school->refresh()->capForGrade('weekly', 2))->toBe(120000);
});

it('does not let a super-admin without a school change settings', function () {
    Role::findOrCreate('super-admin', 'web');
    $super = User::factory()->create(['school_id' => null]);
    $super->assignRole('super-admin');
    $this->actingAs($super);

    $this->get('/admin/settings')->assertForbidden();
});
