<?php

namespace App\Services;

use App\Exceptions\CardException;
use App\Models\Card;
use App\Models\Student;
use App\Support\CardUid;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class CardService
{
    /** First card for a student. */
    public function bind(Student $student, string $rawUid): Card
    {
        $uid = CardUid::normalize($rawUid);

        return $this->guard($uid, fn() => DB::transaction(function () use ($student, $uid) {
            $locked = $this->lockStudent($student);

            if ($this->currentCard($locked)) {
                throw CardException::studentHasCard();
            }

            $this->assertUidFree($locked->school_id, $uid);

            return $this->createCard($locked, $uid);
        }));
    }

    /** Lost card or yearly renewal: retire the current card, activate the new one. Balance is untouched. */
    public function replace(Student $student, string $rawUid): Card
    {
        $uid = CardUid::normalize($rawUid);

        return $this->guard($uid, fn() => DB::transaction(function () use ($student, $uid) {
            $locked = $this->lockStudent($student);

            $this->assertUidFree($locked->school_id, $uid);   // check before retiring the old card

            $this->currentCard($locked)?->update([
                'status' => Card::RETIRED,
                'retired_at' => now(),
            ]);

            return $this->createCard($locked, $uid);
        }));
    }

    public function block(Card $card): Card
    {
        return DB::transaction(function () use ($card) {
            $locked = Card::withoutGlobalScopes()->lockForUpdate()->findOrFail($card->id);

            if ($locked->status === Card::RETIRED) {
                throw CardException::cardRetired();
            }

            if ($locked->status === Card::ACTIVE) {
                $locked->update(['status' => Card::BLOCKED]);
            }

            return $locked;
        });
    }

    public function unblock(Card $card): Card
    {
        return DB::transaction(function () use ($card) {
            $locked = Card::withoutGlobalScopes()->lockForUpdate()->findOrFail($card->id);

            if ($locked->status === Card::RETIRED) {
                throw CardException::cardRetired();
            }

            if ($locked->status === Card::BLOCKED) {
                $locked->update(['status' => Card::ACTIVE]);
            }

            return $locked;
        });
    }

    /** The card a tap is checked against: throws a specific reason if it can't be used. */
    public function resolve(string $rawUid, int $schoolId): Card
    {
        $uid = CardUid::normalize($rawUid);

        $card = Card::withoutGlobalScopes()
            ->with('student.account')
            ->where('school_id', $schoolId)
            ->where('uid', $uid)
            ->first();

        if (!$card) {
            throw CardException::unknownCard();
        }

        if ($card->status === Card::BLOCKED) {
            throw CardException::cardBlocked();
        }

        if ($card->status === Card::RETIRED) {
            throw CardException::cardRetired();
        }

        if (!$card->student->is_active) {
            throw CardException::studentInactive();
        }

        return $card;
    }

    /** The student's active or blocked card, if any. */
    public function currentCard(Student $student): ?Card
    {
        return Card::withoutGlobalScopes()
            ->where('student_id', $student->id)
            ->whereIn('status', [Card::ACTIVE, Card::BLOCKED])
            ->first();
    }

    private function lockStudent(Student $student): Student
    {
        // Serialises card changes for one student
        return Student::withoutGlobalScopes()->lockForUpdate()->findOrFail($student->id);
    }

    private function assertUidFree(int $schoolId, string $uid): void
    {
        $taken = Card::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('uid', $uid)
            ->exists();

        if ($taken) {
            throw CardException::uidInUse($uid);
        }
    }

    private function createCard(Student $student, string $uid): Card
    {
        return Card::create([
            'school_id' => $student->school_id,
            'student_id' => $student->id,
            'uid' => $uid,
            'status' => Card::ACTIVE,
            'activated_at' => now(),
        ]);
    }

    /** Two requests racing for the same UID: the unique index decides, we report it cleanly. */
    private function guard(string $uid, callable $work): Card
    {
        try {
            return $work();
        } catch (UniqueConstraintViolationException) {
            throw CardException::uidInUse($uid);
        }
    }
}