<?php

use App\Exceptions\CardException;
use App\Models\School;
use App\Models\Student;
use App\Services\CardService;
use App\Services\LedgerService;
use App\Support\CardUid;

function cardSchool(string $code = 'C1'): School
{
    return School::firstOrCreate(['code' => $code], ['name' => "School {$code}"]);
}

function cardStudent(?School $school = null): Student
{
    static $n = 0;
    $n++;
    $school ??= cardSchool();

    return Student::create([
        'school_id' => $school->id,
        'student_code' => "K{$n}",
        'name' => "Kid {$n}",
    ]);
}

function expectReason(callable $fn, string $reason): void
{
    try {
        $fn();
    } catch (CardException $e) {
        expect($e->reason)->toBe($reason);

        return;
    }

    \PHPUnit\Framework\Assert::fail("Expected CardException '{$reason}' but nothing was thrown.");
}

it('normalizes common UID formats to one canonical form', function (string $raw) {
    expect(CardUid::normalize($raw))->toBe('04A1B2C3');
})->with(['04A1B2C3', '04:a1:b2:c3', '0x04A1B2C3', ' 04-a1-b2-c3 ', '04 A1 B2 C3']);

it('rejects malformed UIDs', function (string $raw) {
    expectReason(fn() => CardUid::normalize($raw), 'invalid_uid');
})->with(['', 'XYZ12345', '123', '04A1B2C', 'GG:HH:II:JJ']);

it('binds a card to a student', function () {
    $card = app(CardService::class)->bind(cardStudent(), '04:a1:b2:c3');

    expect($card->uid)->toBe('04A1B2C3')
        ->and($card->status)->toBe('active');
});

it('allows only one current card per student', function () {
    $cards = app(CardService::class);
    $student = cardStudent();
    $cards->bind($student, '04A1B2C3');

    expectReason(fn() => $cards->bind($student, '05D4E5F6'), 'student_has_card');
});

it('does not let two students share a UID in one school', function () {
    $cards = app(CardService::class);
    $cards->bind(cardStudent(), '04A1B2C3');

    expectReason(fn() => $cards->bind(cardStudent(), '04A1B2C3'), 'uid_in_use');
});

it('allows the same UID in different schools', function () {
    $cards = app(CardService::class);

    $a = $cards->bind(cardStudent(cardSchool('A')), '04A1B2C3');
    $b = $cards->bind(cardStudent(cardSchool('B')), '04A1B2C3');

    expect($a->id)->not->toBe($b->id);
});

it('refuses a blocked card at the till and accepts it again after unblocking', function () {
    $cards = app(CardService::class);
    $student = cardStudent();
    $card = $cards->bind($student, '04A1B2C3');

    $cards->block($card);
    expectReason(fn() => $cards->resolve('04A1B2C3', $student->school_id), 'card_blocked');

    $cards->unblock($card);
    expect($cards->resolve('04A1B2C3', $student->school_id)->student_id)->toBe($student->id);
});

it('refuses an unknown card', function () {
    $school = cardSchool();

    expectReason(fn() => app(CardService::class)->resolve('AABBCCDD', $school->id), 'unknown_card');
});

it('refuses a card whose student is inactive', function () {
    $cards = app(CardService::class);
    $student = cardStudent();
    $cards->bind($student, '04A1B2C3');
    $student->update(['is_active' => false]);

    expectReason(fn() => $cards->resolve('04A1B2C3', $student->school_id), 'student_inactive');
});

it('keeps the balance when a card is replaced', function () {
    $cards = app(CardService::class);
    $student = cardStudent();
    $old = $cards->bind($student, '04A1B2C3');
    app(LedgerService::class)->topUp($student->account, 5000, 'seed');

    $cards->block($old);
    $new = $cards->replace($student, '05D4E5F6');

    expect($old->refresh()->status)->toBe('retired')
        ->and($new->status)->toBe('active')
        ->and($student->account->refresh()->balance)->toBe(5000);

    expectReason(fn() => $cards->resolve('04A1B2C3', $student->school_id), 'card_retired');
    expect($cards->resolve('05D4E5F6', $student->school_id)->student_id)->toBe($student->id);
});

it('does not retire the old card when the new UID is already taken', function () {
    $cards = app(CardService::class);
    $first = cardStudent();
    $second = cardStudent();
    $cards->bind($first, '04A1B2C3');
    $current = $cards->bind($second, '05D4E5F6');

    expectReason(fn() => $cards->replace($second, '04A1B2C3'), 'uid_in_use');

    expect($current->refresh()->status)->toBe('active');
});

it('cannot unblock a retired card', function () {
    $cards = app(CardService::class);
    $student = cardStudent();
    $old = $cards->bind($student, '04A1B2C3');
    $cards->replace($student, '05D4E5F6');

    expectReason(fn() => $cards->unblock($old), 'card_retired');
});