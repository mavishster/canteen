<?php

use App\Exceptions\CardException;
use App\Models\Card;
use App\Models\School;
use App\Models\Student;
use App\Services\CardService;
use App\Services\LedgerService;
use App\Services\Sis\FakeSisClient;
use App\Services\Sis\SisDatabaseClient;
use App\Services\Sis\SisStudent;
use App\Services\Sis\SisStudentImporter;
use App\Support\SisCardFormat;
use Illuminate\Support\Facades\Schema;

function sisSchool(): School
{
    static $n = 0;
    $n++;

    return School::create(['name' => "SIS School {$n}", 'code' => "SIS{$n}", 'sis_branch_id' => 100 + $n]);
}

function sisKid(string $id, string $name = 'Kid', ?int $grade = 4, ?string $rfid = 'auto'): SisStudent
{
    // 'auto' gives every student their own valid card number
    $rfid = $rfid === 'auto' ? sprintf('%010d', 1000000 + (int) $id) : $rfid;

    return new SisStudent($id, $name, $grade, $rfid);
}

function sisFind(string $code, School $school): ?Student
{
    return Student::withoutGlobalScopes()->where('school_id', $school->id)->where('student_code', $code)->first();
}

function sisImport(School $school, array $students, bool $dry = false)
{
    return app(SisStudentImporter::class)->import($school, new FakeSisClient($students), $dry);
}

// ----------------------------------------------------------- card format

it('converts SIS card numbers into the reader\'s format', function () {
    expect(SisCardFormat::convert(' 0001035627 ', 'same'))->toBe('0001035627')
        ->and(SisCardFormat::convert('0001035627', 'decimal_to_hex'))->toBe('000FCD6B')
        ->and(SisCardFormat::convert('0001035627', 'decimal_to_hex_reversed'))->toBe('6BCD0F00');
});

it('rejects a non-decimal value in a decimal mode and an unknown mode', function () {
    expect(fn () => SisCardFormat::convert('ABC123', 'decimal_to_hex'))->toThrow(CardException::class);
    expect(fn () => SisCardFormat::convert('1', 'nonsense'))->toThrow(InvalidArgumentException::class);
});

// ------------------------------------------------------------ the importer

it('creates students with accounts, grades and cards', function () {
    $school = sisSchool();

    $report = sisImport($school, [sisKid('1001', 'Alice', 3), sisKid('1002', 'Bob', 5)]);

    $alice = sisFind('1001', $school);

    expect($report->created)->toBe(2)
        ->and($report->cardsBound)->toBe(2)
        ->and($report->issues)->toBe([])
        ->and($alice->grade)->toBe(3)
        ->and($alice->account)->not->toBeNull()
        ->and(app(CardService::class)->currentCard($alice)->uid)->toBe('0001001001');
});

it('changes nothing when it runs again', function () {
    $school = sisSchool();
    $students = [sisKid('1001', 'Alice', 3), sisKid('1002', 'Bob', 5)];

    sisImport($school, $students);
    $again = sisImport($school, $students);

    expect($again->created)->toBe(0)
        ->and($again->updated)->toBe(0)
        ->and($again->unchanged)->toBe(2)
        ->and($again->cardsBound)->toBe(0)
        ->and($again->cardsReplaced)->toBe(0)
        ->and($again->cardsUnchanged)->toBe(2)
        ->and(Student::withoutGlobalScopes()->where('school_id', $school->id)->count())->toBe(2)
        ->and(Card::withoutGlobalScopes()->where('school_id', $school->id)->count())->toBe(2);
});

it('applies a new name and a new grade', function () {
    $school = sisSchool();
    sisImport($school, [sisKid('1001', 'Alice', 3)]);

    $report = sisImport($school, [sisKid('1001', 'Alice Smith', 4)]);

    $alice = sisFind('1001', $school);

    expect($report->updated)->toBe(1)
        ->and($alice->name)->toBe('Alice Smith')
        ->and($alice->grade)->toBe(4);
});

it('replaces the card on a new RFID and keeps the balance', function () {
    $school = sisSchool();
    sisImport($school, [sisKid('1001', 'Alice', 3, '0001000001')]);
    $alice = sisFind('1001', $school);
    app(LedgerService::class)->topUp($alice->account, 5000, 'seed-1');

    $report = sisImport($school, [sisKid('1001', 'Alice', 3, '0001000002')]);

    expect($report->cardsReplaced)->toBe(1)
        ->and(Card::withoutGlobalScopes()->where('uid', '0001000001')->first()->status)->toBe('retired')
        ->and(app(CardService::class)->currentCard($alice)->uid)->toBe('0001000002')
        ->and($alice->account->refresh()->balance)->toBe(5000);
});

it('never unblocks a card a parent blocked', function () {
    $school = sisSchool();
    sisImport($school, [sisKid('1001', 'Alice', 3, '0001000001')]);
    $alice = sisFind('1001', $school);
    app(CardService::class)->block(app(CardService::class)->currentCard($alice));

    $report = sisImport($school, [sisKid('1001', 'Alice', 3, '0001000001')]);

    expect($report->cardsUnchanged)->toBe(1)
        ->and(app(CardService::class)->currentCard($alice)->status)->toBe('blocked');
});

it('reports problem cards instead of guessing', function () {
    $school = sisSchool();

    // A canteen student who already holds card ...0009
    $existing = Student::create(['school_id' => $school->id, 'student_code' => 'MANUAL', 'name' => 'Manual']);
    app(CardService::class)->bind($existing, '0001000009');

    $report = sisImport($school, [
        sisKid('2001', 'No card', 4, null),
        sisKid('2002', 'Bad card', 4, 'not-a-card'),
        sisKid('2003', 'First', 4, '0001000003'),
        sisKid('2004', 'Second', 4, '0001000003'),   // the same card in the SIS
        sisKid('2005', 'Taken', 4, '0001000009'),    // already belongs to another canteen student
    ]);

    $types = collect($report->issues)->pluck('type')->sort()->values()->all();

    expect($types)->toBe(['card_in_use', 'duplicate_card', 'invalid_card', 'no_card'])
        ->and($report->cardsBound)->toBe(1)   // only "First" got a card
        ->and(app(CardService::class)->currentCard(sisFind('2004', $school)))->toBeNull()
        ->and(app(CardService::class)->currentCard($existing)->uid)->toBe('0001000009');
});

it('lists students the SIS no longer has, without deactivating them', function () {
    $school = sisSchool();
    Student::create(['school_id' => $school->id, 'student_code' => 'OLD1', 'name' => 'Left school']);

    $report = sisImport($school, [sisKid('1001', 'Alice')]);

    expect(collect($report->missing)->pluck('student_code')->all())->toBe(['OLD1'])
        ->and(sisFind('OLD1', $school)->is_active)->toBeTrue();
});

it('refuses to run when the SIS returns no students', function () {
    $school = sisSchool();
    sisImport($school, [sisKid('1001', 'Alice')]);

    expect(fn () => sisImport($school, []))->toThrow(RuntimeException::class);

    expect(sisFind('1001', $school)->is_active)->toBeTrue();
});

it('saves nothing on a dry run but reports what would happen', function () {
    $school = sisSchool();

    $report = sisImport($school, [sisKid('1001', 'Alice'), sisKid('1002', 'Bob')], dry: true);

    expect($report->created)->toBe(2)
        ->and($report->cardsBound)->toBe(2)
        ->and(Student::withoutGlobalScopes()->where('school_id', $school->id)->count())->toBe(0)
        ->and(Card::withoutGlobalScopes()->where('school_id', $school->id)->count())->toBe(0);
});

it('refuses a school that is not linked to a SIS branch', function () {
    $school = School::create(['name' => 'Unlinked', 'code' => 'UNLINKED']);

    expect(fn () => sisImport($school, [sisKid('1001')]))->toThrow(InvalidArgumentException::class);
});

it('never touches another school\'s students', function () {
    $mine = sisSchool();
    $other = sisSchool();
    $theirs = Student::create(['school_id' => $other->id, 'student_code' => '1001', 'name' => 'Other school kid']);

    sisImport($mine, [sisKid('1001', 'My kid')]);

    expect($theirs->refresh()->name)->toBe('Other school kid')
        ->and(sisFind('1001', $mine)->name)->toBe('My kid');
});

it('applies the configured card format', function () {
    config(['sis.card_format' => 'decimal_to_hex']);
    $school = sisSchool();

    sisImport($school, [sisKid('1001', 'Alice', 3, '0001035627')]);

    expect(app(CardService::class)->currentCard(sisFind('1001', $school))->uid)->toBe('000FCD6B');
});

// ------------------------------------------------------------- commands

it('imports through the artisan command', function () {
    config(['sis.driver' => 'fake']);
    app()->instance(FakeSisClient::class, new FakeSisClient([sisKid('1001', 'Alice'), sisKid('1002', 'Bob')]));
    $school = sisSchool();

    $this->artisan('sis:import-students', ['school' => $school->code, '--dry-run' => true])->assertExitCode(0);
    expect(Student::withoutGlobalScopes()->where('school_id', $school->id)->count())->toBe(0);

    $this->artisan('sis:import-students', ['school' => $school->code])->assertExitCode(0);
    expect(Student::withoutGlobalScopes()->where('school_id', $school->id)->count())->toBe(2);
});

it('explains when the school is not linked to the SIS', function () {
    School::create(['name' => 'Unlinked', 'code' => 'UNLINKED2']);

    $this->artisan('sis:import-students', ['school' => 'UNLINKED2'])->assertExitCode(1);
});

it('shows the card formats for a SIS value', function () {
    $this->artisan('sis:card-formats', ['rfid' => '0001035627'])
        ->expectsOutputToContain('000FCD6B')
        ->expectsOutputToContain('6BCD0F00')
        ->assertExitCode(0);
});

// -------------------------------------------------------- the SIS database query

it('reads only current students of the active year in the branch', function () {
    config(['sis.database' => ['driver' => 'sqlite', 'database' => ':memory:']]);
    $client = new SisDatabaseClient();
    $sis = $client->connection();

    Schema::connection('sis')->create('users', function ($t) {
        $t->increments('id');
        $t->string('name');
        $t->string('user_type')->nullable();
        $t->integer('user_status')->default(1);
        $t->string('rfid')->nullable();
        $t->integer('branch_id')->nullable();
    });
    Schema::connection('sis')->create('academic_year', function ($t) {
        $t->increments('academic_year_id');
        $t->integer('is_active')->default(0);
        $t->dateTime('deleted_at')->nullable();
    });
    Schema::connection('sis')->create('classrooms', function ($t) {
        $t->increments('classroom_id');
        $t->integer('grade_level')->nullable();
    });
    Schema::connection('sis')->create('students_classroom', function ($t) {
        $t->increments('sc_id');
        $t->integer('academic_year_id')->nullable();
        $t->integer('classroom_id')->nullable();
        $t->integer('student_id')->nullable();
    });

    $user = fn (int $id, string $name, string $type, int $status, ?string $rfid, int $branch) => $sis->table('users')
        ->insert(['id' => $id, 'name' => $name, 'user_type' => $type, 'user_status' => $status, 'rfid' => $rfid, 'branch_id' => $branch]);

    $user(1, ' Alice ', 'student', 1, ' 0001000001 ', 1);   // current; spaces get trimmed
    $user(2, 'Bob', 'student', 1, '', 1);                    // current; empty RFID becomes null
    $user(3, 'Inactive', 'student', 0, '0001000003', 1);     // inactive
    $user(4, 'A Parent', 'parent', 1, null, 1);              // not a student
    $user(5, 'A Teacher', 'staff', 1, null, 1);              // not a student
    $user(6, 'Elsewhere', 'student', 1, '0001000006', 2);    // another branch
    $user(7, 'Last year', 'student', 1, '0001000007', 1);    // only in an old academic year
    $user(8, 'Cara', 'student', 1, '0001000008', 1);         // two classrooms: the higher grade wins

    $sis->table('academic_year')->insert([
        ['academic_year_id' => 1, 'is_active' => 1, 'deleted_at' => null],
        ['academic_year_id' => 2, 'is_active' => 0, 'deleted_at' => null],
        ['academic_year_id' => 3, 'is_active' => 1, 'deleted_at' => '2025-01-01 00:00:00'],   // deleted
    ]);
    $sis->table('classrooms')->insert([
        ['classroom_id' => 10, 'grade_level' => 3],
        ['classroom_id' => 11, 'grade_level' => 4],
    ]);
    $sis->table('students_classroom')->insert([
        ['academic_year_id' => 1, 'classroom_id' => 10, 'student_id' => 1],
        ['academic_year_id' => 1, 'classroom_id' => 11, 'student_id' => 2],
        ['academic_year_id' => 1, 'classroom_id' => 10, 'student_id' => 3],
        ['academic_year_id' => 1, 'classroom_id' => 10, 'student_id' => 4],
        ['academic_year_id' => 1, 'classroom_id' => 10, 'student_id' => 5],
        ['academic_year_id' => 1, 'classroom_id' => 10, 'student_id' => 6],
        ['academic_year_id' => 2, 'classroom_id' => 10, 'student_id' => 7],
        ['academic_year_id' => 1, 'classroom_id' => 10, 'student_id' => 8],
        ['academic_year_id' => 1, 'classroom_id' => 11, 'student_id' => 8],
        ['academic_year_id' => 3, 'classroom_id' => 10, 'student_id' => 2],
    ]);

    $students = $client->students(1);

    expect(collect($students)->map(fn ($s) => [$s->id, $s->name, $s->grade, $s->rfid])->all())->toBe([
        ['1', 'Alice', 3, '0001000001'],
        ['2', 'Bob', 4, null],
        ['8', 'Cara', 4, '0001000008'],
    ]);

    // The student object carries nothing but these four fields
    expect(array_keys(get_object_vars($students[0])))->toBe(['id', 'name', 'grade', 'rfid']);
});
