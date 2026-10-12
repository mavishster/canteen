<?php

namespace App\Services;

use App\Exceptions\CardException;
use App\Models\Card;
use App\Models\Student;
use App\Support\CardUid;

/**
 * The till has one box. It can hold a card number (typed by the reader) or a student number (typed by the cashier).
 * A card that exists in the school always wins: it is a tap.
 */
class StudentLookup
{
    /** @return array{type: 'card'|'student'|'none', student: Student|null, looks_like_card: bool} */
    public function identify(int $schoolId, string $input): array
    {
        $input = trim($input);
        $looksLikeCard = false;

        try {
            $uid = CardUid::normalize($input);
            $looksLikeCard = true;

            $isKnownCard = Card::withoutGlobalScopes()
                ->where('school_id', $schoolId)
                ->where('uid', $uid)
                ->exists();

            if ($isKnownCard) {
                return ['type' => 'card', 'student' => null, 'looks_like_card' => true];
            }
        } catch (CardException) {
            // not shaped like a card number: it may still be a student number
        }

        $student = Student::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('student_code', $input)
            ->first();

        return $student
            ? ['type' => 'student', 'student' => $student, 'looks_like_card' => $looksLikeCard]
            : ['type' => 'none', 'student' => null, 'looks_like_card' => $looksLikeCard];
    }
}
