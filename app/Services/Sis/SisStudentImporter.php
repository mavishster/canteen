<?php

namespace App\Services\Sis;

use App\Exceptions\CardException;
use App\Models\Card;
use App\Models\School;
use App\Models\Student;
use App\Services\CardService;
use App\Support\CardUid;
use App\Support\SisCardFormat;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Brings students and their cards in line with the SIS. Safe to run again and again.
 * It never deletes anything and never takes a card away from another student: problems are reported.
 */
class SisStudentImporter
{
    public function __construct(private CardService $cards)
    {
    }

    public function import(School $school, SisClient $client, bool $dryRun = false): ImportReport
    {
        if (! $school->sis_branch_id) {
            throw new InvalidArgumentException("School {$school->code} has no sis_branch_id.");
        }

        $sisStudents = $client->students((int) $school->sis_branch_id);

        // Fail closed: an empty answer is almost certainly an outage, not "every student left"
        if (! $sisStudents) {
            throw new RuntimeException('The SIS returned no students. Nothing was changed.');
        }

        $report = new ImportReport();
        $seenCards = [];   // card => student_code, to catch one card given to two students in the SIS

        if ($dryRun) {
            DB::beginTransaction();   // everything below really runs, then is rolled back
        }

        try {
            foreach ($sisStudents as $sis) {
                try {
                    $this->importOne($school, $sis, $report, $seenCards);
                } catch (Throwable $e) {
                    report($e);   // one broken record must not stop the rest
                    $report->issue($sis->id, $sis->name, 'error', $e->getMessage());
                }
            }

            $this->findMissing($school, $sisStudents, $report);
        } finally {
            if ($dryRun) {
                DB::rollBack();
            }
        }

        return $report;
    }

    /** @param array<string, string> $seenCards */
    private function importOne(School $school, SisStudent $sis, ImportReport $report, array &$seenCards): void
    {
        $student = Student::withoutGlobalScopes()
            ->where('school_id', $school->id)
            ->where('student_code', $sis->id)
            ->first();

        if (! $student) {
            $student = Student::create([
                'school_id' => $school->id,
                'student_code' => $sis->id,
                'name' => $sis->name,
                'grade' => $sis->grade,
            ]);
            $report->created++;
        } else {
            $changes = [];

            if ($student->name !== $sis->name) {
                $changes['name'] = $sis->name;
            }

            if ((string) $student->grade !== (string) $sis->grade) {
                $changes['grade'] = $sis->grade;
            }

            if ($changes) {
                $student->update($changes);
                $report->updated++;
            } else {
                $report->unchanged++;
            }
        }

        $this->syncCard($school, $student, $sis, $report, $seenCards);
    }

    /** @param array<string, string> $seenCards */
    private function syncCard(School $school, Student $student, SisStudent $sis, ImportReport $report, array &$seenCards): void
    {
        if ($sis->rfid === null) {
            $report->issue($sis->id, $sis->name, 'no_card', 'The SIS has no RFID number for this student.');

            return;
        }

        try {
            $uid = CardUid::normalize(SisCardFormat::convert($sis->rfid, (string) config('sis.card_format', 'same')));
        } catch (CardException) {
            $report->issue($sis->id, $sis->name, 'invalid_card', "The RFID '{$sis->rfid}' cannot be read as a card number.");

            return;
        }

        if (isset($seenCards[$uid]) && $seenCards[$uid] !== $sis->id) {
            $report->issue($sis->id, $sis->name, 'duplicate_card', "The same RFID is also given to student {$seenCards[$uid]} in the SIS. Not bound.");

            return;
        }
        $seenCards[$uid] = $sis->id;

        $current = $this->cards->currentCard($student);

        if ($current && $current->uid === $uid) {
            $report->cardsUnchanged++;   // includes a card a parent blocked: the import never unblocks

            return;
        }

        $owner = Card::withoutGlobalScopes()
            ->where('school_id', $school->id)
            ->where('uid', $uid)
            ->first();

        if ($owner && (int) $owner->student_id !== (int) $student->id) {
            $ownerCode = Student::withoutGlobalScopes()->whereKey($owner->student_id)->value('student_code');
            $report->issue($sis->id, $sis->name, 'card_in_use', "This card already belongs to student {$ownerCode} in the canteen. Not changed.");

            return;
        }

        if ($owner) {
            $report->issue($sis->id, $sis->name, 'card_retired', 'This card was retired earlier for this student and cannot be reused automatically.');

            return;
        }

        try {
            if ($current) {
                $this->cards->replace($student, $uid);   // yearly renewal or a lost card: the balance stays
                $report->cardsReplaced++;
            } else {
                $this->cards->bind($student, $uid);
                $report->cardsBound++;
            }
        } catch (CardException $e) {
            $report->issue($sis->id, $sis->name, 'card_error', $e->getMessage());
        }
    }

    /** Active canteen students the SIS no longer lists: reported for a person to decide (they may hold a balance). */
    private function findMissing(School $school, array $sisStudents, ImportReport $report): void
    {
        $codes = array_map(fn (SisStudent $s) => $s->id, $sisStudents);

        Student::withoutGlobalScopes()
            ->where('school_id', $school->id)
            ->where('is_active', true)
            ->whereNotIn('student_code', $codes)
            ->orderBy('student_code')
            ->get(['student_code', 'name'])
            ->each(fn ($s) => $report->missing[] = ['student_code' => $s->student_code, 'name' => $s->name]);
    }
}
