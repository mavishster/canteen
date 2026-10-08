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
