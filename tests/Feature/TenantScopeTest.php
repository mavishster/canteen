<?php

use App\Models\School;
use App\Models\Student;
use App\Models\User;

it('only shows records from the logged-in user\'s school', function () {
    $a = School::create(['name' => 'School A', 'code' => 'A']);
    $b = School::create(['name' => 'School B', 'code' => 'B']);

    Student::create(['school_id' => $a->id, 'student_code' => 'S1', 'name' => 'Alice', 'grade' => 1]);
    Student::create(['school_id' => $b->id, 'student_code' => 'S1', 'name' => 'Bob', 'grade' => 1]);

    $this->actingAs(User::factory()->create(['school_id' => $a->id]));

    expect(Student::count())->toBe(1)
        ->and(Student::first()->name)->toBe('Alice');
});

it('creates an account automatically for each new student', function () {
    $school = School::create(['name' => 'School A', 'code' => 'A']);

    $student = Student::create(['school_id' => $school->id, 'student_code' => 'S1', 'name' => 'Alice']);

    expect($student->account)->not->toBeNull()
        ->and($student->account->balance)->toBe(0);
});