<?php

use App\Models\AuditLog;
use App\Models\Card;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Services\CardService;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;

function ciUser(School $school, string $role = 'admin'): User
{
    Role::findOrCreate($role, 'web');

    $user = User::factory()->create(['school_id' => $school->id]);
    $user->assignRole($role);

    return $user;
}

function ciSchool(): School
{
    static $n = 0;
    $n++;

    return School::create(['name' => "Import School {$n}", 'code' => "IMP{$n}"]);
}

function ciKid(School $school, string $code, bool $active = true): Student
{
    return Student::create(['school_id' => $school->id, 'student_code' => $code, 'name' => "Kid {$code}", 'grade' => 3, 'is_active' => $active])->refresh();
}

function ciPaste($test, string $text)
{
    return $test->post('/admin/cards/import/preview', ['paste' => $text]);
}

/** @return array<int, string> the status of each line, in order */
function ciStatuses($response): array
{
    return collect($response->viewData('rows'))->pluck('status')->all();
}

// ------------------------------------------------------------------ the check

it('checks every line and says what will happen to it', function () {
    $school = ciSchool();
    $a = ciKid($school, 'A1');
    $b = ciKid($school, 'B1');
    ciKid($school, 'C1', active: false);
    $d = ciKid($school, 'D1');
    ciKid($school, 'E1');
    $f = ciKid($school, 'F1');

    app(CardService::class)->bind($b, 'BBBB0000');
    app(CardService::class)->bind($f, 'FFFF0001');

    $this->actingAs(ciUser($school));

    $response = ciPaste($this, implode("\n", [
        'A1,AAAA0001',       // ok
        'B1,BBBB0001',       // the student already has a card
        'C1,CCCC0002',       // inactive student
        'ZZZ,AAAA0003',      // nobody has that number
        'D1,FFFF0001',       // the card belongs to someone else
        'D1,DDDD0001',       // ok
        'D1,DDDD0002',       // the same student twice
        'E1,AAAA0001',       // the same card twice
        'E1,',               // no card number
    ]))->assertOk();

    expect(ciStatuses($response))->toBe([
        'ok', 'student_has_card', 'inactive_student', 'unknown_student', 'card_in_use', 'ok', 'duplicate_student', 'duplicate_card', 'invalid_card',
    ])
        ->and($response->viewData('ready'))->toBe(2)
        ->and($response->viewData('total'))->toBe(9)
        ->and(Card::withoutGlobalScopes()->count())->toBe(2);   // checking changes nothing
});

it('reads a file with a header, semicolons, a BOM, quotes and blank lines', function () {
    $school = ciSchool();
    ciKid($school, 'S1');
    ciKid($school, 'S2');
    ciKid($school, 'S3');
    $this->actingAs(ciUser($school));

    $csv = "\xEF\xBB\xBFstudent_code;card;name\r\n\r\n\"S1\";AAAA1111;Kid\r\nS2;BBBB2222\r\n\r\nS3;\"CCCC3333\"\r\n";

    $response = $this->post('/admin/cards/import/preview', ['file' => UploadedFile::fake()->createWithContent('cards.csv', $csv)])->assertOk();

    expect(collect($response->viewData('rows'))->map(fn ($r) => [$r['line'], $r['code'], $r['card']])->all())
        ->toBe([[3, 'S1', 'AAAA1111'], [4, 'S2', 'BBBB2222'], [6, 'S3', 'CCCC3333']])
        ->and($response->viewData('ready'))->toBe(3);
});

it('reads tabs too', function () {
    $school = ciSchool();
    ciKid($school, 'T1');
    $this->actingAs(ciUser($school));

    expect(ciStatuses(ciPaste($this, "student number\tcard\nT1\tAAAA9999")))->toBe(['ok']);
});

it('does not see students of another school', function () {
    $mine = ciSchool();
    $theirs = ciSchool();
    ciKid($theirs, 'X1');
    $this->actingAs(ciUser($mine));

    expect(ciStatuses(ciPaste($this, 'X1,AAAA0001')))->toBe(['unknown_student']);
});

it('refuses an empty upload, a file with no lines, and too many lines', function () {
    $school = ciSchool();
    $this->actingAs(ciUser($school));

    $this->post('/admin/cards/import/preview', [])->assertSessionHasErrors('file');
    ciPaste($this, "student_code,card\n\n")->assertSessionHasErrors('file');

    $many = implode("\n", array_map(fn ($i) => "S{$i},AAAA" . str_pad((string) $i, 4, '0', STR_PAD_LEFT), range(1, 2001)));
    ciPaste($this, $many)->assertSessionHasErrors('file');
});

// -------------------------------------------------------------------- applying

it('binds the cards after the admin confirms, and records it', function () {
    $school = ciSchool();
    $a = ciKid($school, 'A1');
    $d = ciKid($school, 'D1');
    $b = ciKid($school, 'B1');
    app(CardService::class)->bind($b, 'BBBB0000');
    $this->actingAs(ciUser($school));

    $token = ciPaste($this, "A1,AAAA0001\nB1,BBBB0001\nD1,DDDD0001\nZZZ,AAAA0003")->viewData('token');

    $this->post('/admin/cards/import/apply', ['token' => $token])->assertRedirect(route('admin.cards.import.result'));

    expect(Card::withoutGlobalScopes()->where('student_id', $a->id)->value('uid'))->toBe('AAAA0001')
        ->and(Card::withoutGlobalScopes()->where('student_id', $d->id)->value('uid'))->toBe('DDDD0001')
        ->and(Card::withoutGlobalScopes()->where('student_id', $b->id)->value('uid'))->toBe('BBBB0000')   // never replaced
        ->and(AuditLog::withoutGlobalScopes()->where('action', 'card.bound')->count())->toBe(2)             // the two new ones (B1's was bound by the system, before anyone signed in)
        ->and(AuditLog::withoutGlobalScopes()->where('action', 'cards.imported')->first()->summary)->toContain('2 bound')->toContain('2 skipped');

    $this->get('/admin/cards/import/result')->assertOk()->assertSee('2 card(s) bound.');
});

it('looks again before binding: a card taken since the check is skipped, not bound', function () {
    $school = ciSchool();
    $a = ciKid($school, 'A1');
    $other = ciKid($school, 'O1');
    $this->actingAs(ciUser($school));

    $token = ciPaste($this, 'A1,AAAA0001')->viewData('token');

    app(CardService::class)->bind($other, 'AAAA0001');   // someone else took it meanwhile

    $this->post('/admin/cards/import/apply', ['token' => $token])->assertRedirect();

    expect(Card::withoutGlobalScopes()->where('student_id', $a->id)->exists())->toBeFalse()
        ->and(Card::withoutGlobalScopes()->where('student_id', $other->id)->value('uid'))->toBe('AAAA0001');

    $this->get('/admin/cards/import/result')->assertOk()->assertSee('0 card(s) bound.')->assertSee('This card is already registered to another student.');
});

it('does not apply the same check twice, or one that does not exist', function () {
    $school = ciSchool();
    ciKid($school, 'A1');
    $this->actingAs(ciUser($school));

    $token = ciPaste($this, 'A1,AAAA0001')->viewData('token');

    $this->post('/admin/cards/import/apply', ['token' => $token])->assertRedirect(route('admin.cards.import.result'));
    $this->post('/admin/cards/import/apply', ['token' => $token])->assertRedirect(route('admin.cards.import'))->assertSessionHasErrors('file');
    $this->post('/admin/cards/import/apply', ['token' => str_repeat('x', 32)])->assertSessionHasErrors('file');
    $this->post('/admin/cards/import/apply', ['token' => 'short'])->assertSessionHasErrors('token');

    expect(Card::withoutGlobalScopes()->count())->toBe(1);
});

it('sends someone with no result to the form', function () {
    $this->actingAs(ciUser(ciSchool()))->get('/admin/cards/import/result')->assertRedirect(route('admin.cards.import'));
});

// -------------------------------------------------------------- the template list

it('lists only the students who still need a card, ready to fill in', function () {
    $school = ciSchool();
    $has = ciKid($school, 'H1');
    ciKid($school, 'N1');
    ciKid($school, 'N2');
    ciKid($school, 'G1', active: false);
    app(CardService::class)->bind($has, 'AAAA0001');

    $other = ciSchool();
    ciKid($other, 'Z9');

    $this->actingAs(ciUser($school));

    $csv = $this->get('/admin/cards/missing.csv')->assertOk()->streamedContent();

    expect($csv)->toContain('student_code,card,name,grade')
        ->toContain('N1,,"Kid N1",3')
        ->toContain('N2,,')
        ->not->toContain('H1')->not->toContain('G1')->not->toContain('Z9')
        ->and(AuditLog::withoutGlobalScopes()->where('action', 'report.exported')->count())->toBe(1);
});

it('gives back a file the import can read', function () {
    $school = ciSchool();
    ciKid($school, 'N1');
    $this->actingAs(ciUser($school));

    $csv = $this->get('/admin/cards/missing.csv')->streamedContent();
    $filled = str_replace('N1,,', 'N1,AAAA5555,', $csv);

    expect(ciStatuses(ciPaste($this, $filled)))->toBe(['ok']);
});

// ---------------------------------------------------------------------- access

it('is for admins only, and shows the pages in the menu', function () {
    $school = ciSchool();

    foreach (['cashier', 'manager', 'parent'] as $role) {
        $this->actingAs(ciUser($school, $role));
        $this->get('/admin/cards/import')->assertForbidden();
        $this->get('/admin/cards/missing.csv')->assertForbidden();
        $this->post('/admin/cards/import/preview', ['paste' => 'A1,AAAA0001'])->assertForbidden();
        $this->post('/admin/cards/import/apply', ['token' => str_repeat('a', 32)])->assertForbidden();
    }

    $this->actingAs(ciUser($school))->get('/admin/cards/import')->assertOk()->assertSee('Import cards')->assertSee(route('admin.cards.import'), false);
});
