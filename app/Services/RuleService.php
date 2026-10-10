<?php

namespace App\Services;

use App\Exceptions\RuleException;
use App\Models\Account;
use App\Models\Product;
use App\Models\Sale;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentBan;
use Carbon\CarbonInterface;
use InvalidArgumentException;

class RuleService
{
    /**
     * Called by SaleService inside the sale transaction, while the account row is locked.
     * Throws a RuleException with a clear reason; returns quietly when the purchase is allowed.
     *
     * @param  array<int, array{product_id:int, name:string, quantity:int, line_total:int}>  $lines
     */
    public function check(School $school, Student $student, Account $account, array $lines, int $total): void
    {
        $now = now()->setTimezone($school->timezone);

        $this->assertBuyingHours($school, $now);
        $this->assertNoBans($student, $lines);
        $this->assertWithinLimits($school, $student, $account, $total, $now);
    }

    // ------------------------------------------------------- parent controls

    /** Personal limits (minor units, null = none). They can be lower than the school maximum, never higher. */
    public function setLimits(Student $student, ?int $daily, ?int $weekly): Account
    {
        $school = School::findOrFail($student->school_id);

        foreach (['daily' => $daily, 'weekly' => $weekly] as $kind => $value) {
            if ($value === null) {
                continue;
            }

            if ($value <= 0) {
                throw new InvalidArgumentException('A limit must be greater than zero, or null for no limit.');
            }

            $max = $school->capForGrade($kind, $student->grade);

            if ($max !== null && $value > $max) {
                throw RuleException::limitAboveSchoolMax($kind, $max, $school->currency);
            }
        }

        $account = Account::withoutGlobalScopes()->where('student_id', $student->id)->firstOrFail();
        $account->update(['daily_limit' => $daily, 'weekly_limit' => $weekly]);

        return $account;
    }

    public function banProduct(Student $student, Product $product): StudentBan
    {
        if ((int) $product->school_id !== (int) $student->school_id) {
            throw new InvalidArgumentException('That product belongs to another school.');
        }

        return StudentBan::withoutGlobalScopes()->firstOrCreate([
            'school_id' => $student->school_id,
            'student_id' => $student->id,
            'product_id' => $product->id,
        ]);
    }

    public function banCategory(Student $student, \App\Models\Category $category): StudentBan
    {
        if ((int) $category->school_id !== (int) $student->school_id) {
            throw new InvalidArgumentException('That category belongs to another school.');
        }

        return StudentBan::withoutGlobalScopes()->firstOrCreate([
            'school_id' => $student->school_id,
            'student_id' => $student->id,
            'category_id' => $category->id,
        ]);
    }

    public function unban(StudentBan $ban): void
    {
        $ban->delete();
    }

    // --------------------------------------------------------------- checks

    private function assertBuyingHours(School $school, CarbonInterface $now): void
    {
        $hours = $school->buyingHours();

        if (! $hours) {
            return;
        }

        $days = $hours['days'] ?? [1, 2, 3, 4, 5, 6, 7];
        $windows = $hours['windows'] ?? [];

        $dayOk = in_array($now->isoWeekday(), $days, true);
        $timeOk = $windows === [];   // no windows listed = open all day

        foreach ($windows as [$from, $to]) {
            $start = $now->copy()->setTimeFromTimeString($from);
            $end = $now->copy()->setTimeFromTimeString($to);

            if ($now->between($start, $end)) {
                $timeOk = true;
                break;
            }
        }

        if (! ($dayOk && $timeOk)) {
            throw RuleException::outsideHours($windows);
        }
    }

    private function assertNoBans(Student $student, array $lines): void
    {
        $bans = StudentBan::withoutGlobalScopes()->where('student_id', $student->id)->get();

        if ($bans->isEmpty()) {
            return;
        }

        $bannedProducts = $bans->pluck('product_id')->filter()->all();
        $bannedCategories = $bans->pluck('category_id')->filter()->all();

        $blocked = Product::withoutGlobalScopes()
            ->whereIn('id', array_column($lines, 'product_id'))
            ->get(['id', 'name', 'category_id'])
            ->filter(fn ($p) => in_array($p->id, $bannedProducts)
                || ($p->category_id && in_array($p->category_id, $bannedCategories)))
            ->pluck('name')
            ->all();

        if ($blocked) {
            throw RuleException::bannedProducts($blocked);
        }
    }

    private function assertWithinLimits(School $school, Student $student, Account $account, int $total, CarbonInterface $now): void
    {
        $periods = [
            'daily' => $now->copy()->startOfDay()->utc(),
            'weekly' => $now->copy()->startOfWeek()->utc(),   // weeks start on Monday, in school time
        ];

        foreach ($periods as $kind => $since) {
            $caps = array_filter([
                $school->capForGrade($kind, $student->grade),
                $account->{$kind . '_limit'},
            ], fn ($cap) => $cap !== null);

            if (! $caps) {
                continue;
            }

            $cap = (int) min($caps);

            // Safe from races: the caller holds the account row lock
            $spent = (int) Sale::withoutGlobalScopes()
                ->where('account_id', $account->id)
                ->whereNull('voided_at')
                ->where('created_at', '>=', $since)
                ->sum('total');

            if ($spent + $total > $cap) {
                throw RuleException::overLimit($kind, max(0, $cap - $spent), $school->currency);
            }
        }
    }
}
