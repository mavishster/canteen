<?php

namespace App\Services;

use App\Exceptions\RuleException;
use App\Models\Account;
use App\Models\Category;
use App\Models\Product;
use App\Models\Sale;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentBan;
use App\Support\Money;
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

        $this->assertSingleSale($school, $total);
        $this->assertBuyingHours($school, $now);
        $this->assertCategoryHours($lines, $now);
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

    public function banCategory(Student $student, Category $category): StudentBan
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

    /**
     * The most one purchase may cost (settings.till.max_sale). A copied card can then never empty an account in one go.
     * Meal plans are bought from the wallet, not at the till, so this does not apply to them.
     */
    private function assertSingleSale(School $school, int $total): void
    {
        $max = (int) data_get($school->settings, 'till.max_sale', 0);

        if ($max > 0 && $total > $max) {
            throw new RuleException(
                'over_sale_limit',
                'A single purchase cannot be more than ' . Money::format($max, $school->currency) . '.'
            );
        }
    }

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

    /**
     * Some categories (snacks, sweets) may be bought only at certain times or on certain days.
     * One closed item in the cart refuses the whole sale, and the message names the category and when it is open.
     *
     * @param  array<int, array{product_id:int}>  $lines
     */
    private function assertCategoryHours(array $lines, CarbonInterface $now): void
    {
        $categoryIds = Product::withoutGlobalScopes()
            ->whereIn('id', array_column($lines, 'product_id'))
            ->whereNotNull('category_id')
            ->pluck('category_id')
            ->unique()
            ->all();

        if (! $categoryIds) {
            return;
        }

        $closed = Category::withoutGlobalScopes()
            ->whereIn('id', $categoryIds)
            ->get()
            ->filter(fn (Category $c) => $c->hasSellingHours() && ! $this->categoryOpen($c, $now));

        if ($closed->isEmpty()) {
            return;
        }

        throw new RuleException(
            'category_closed',
            $closed->map(fn (Category $c) => $c->name . ' can only be bought ' . $this->describeHours($c) . '.')->implode(' ')
        );
    }

    private function categoryOpen(Category $category, CarbonInterface $now): bool
    {
        $days = $category->sell_days;

        if (is_array($days) && $days !== [] && ! in_array($now->isoWeekday(), array_map('intval', $days), true)) {
            return false;
        }

        if ($category->sell_from !== null && $category->sell_to !== null) {
            $time = $now->format('H:i');

            // From and to are both inclusive
            if ($time < substr((string) $category->sell_from, 0, 5) || $time > substr((string) $category->sell_to, 0, 5)) {
                return false;
            }
        }

        return true;
    }

    private function describeHours(Category $category): string
    {
        $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
        $parts = [];

        if ($category->sell_from !== null && $category->sell_to !== null) {
            $parts[] = 'between ' . substr((string) $category->sell_from, 0, 5) . ' and ' . substr((string) $category->sell_to, 0, 5);
        }

        if (! empty($category->sell_days)) {
            $parts[] = 'on ' . implode(', ', array_map(fn ($d) => $names[(int) $d] ?? $d, $category->sell_days));
        }

        return implode(' ', $parts);
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

            // Safe from races: the caller holds the account row lock. Refunded sales do not count.
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
