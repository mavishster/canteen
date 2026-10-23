<?php

use App\Exceptions\RuleException;
use App\Exceptions\SaleException;
use App\Models\GuardianLink;
use App\Models\ParentMessage;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Services\LedgerService;
use Spatie\Permission\Models\Role;

function ts5Khmer(callable $fn): mixed
{
    app()->setLocale('km');

    try {
        return $fn();
    } finally {
        app()->setLocale('en');
    }
}

function ts5School(): School
{
    static $n = 0;
    $n++;

    return School::create(['name' => "Stage3c School {$n}", 'code' => "S3C{$n}"]);
}

function ts5User(School $school, string $role, array $attributes = []): User
{
    Role::findOrCreate($role, 'web');

    $user = User::factory()->create(['school_id' => $school->id] + $attributes);
    $user->assignRole($role);

    return $user->refresh();
}

// ------------------------------------------------- the English stays exactly as it was

it('keeps the English wording of the rebuilt messages', function () {
    expect(SaleException::outOfStock('Cola', 2)->getMessage())->toBe('Cola: only 2 left.')
        ->and(SaleException::outOfStock('Cola', 0)->getMessage())->toBe('Cola is out of stock.')
        ->and(RuleException::outsideHours([['08:00', '10:00'], ['12:00', '13:00']])->getMessage())
            ->toBe('Purchases are not allowed at this time. Buying hours: 08:00–10:00, 12:00–13:00.')
        ->and(RuleException::outsideHours([])->getMessage())->toBe('Purchases are not allowed at this time.')
        ->and(RuleException::overLimit('daily', 150, 'USD')->getMessage())->toBe('Daily spending limit reached. Remaining today: $1.50.')
        ->and(RuleException::overLimit('weekly', 150, 'USD')->getMessage())->toBe('Weekly spending limit reached. Remaining this week: $1.50.')
        ->and(RuleException::limitAboveSchoolMax('weekly', 5000, 'USD')->getMessage())->toBe('The weekly limit cannot be above the school maximum of $50.00.');
});

it('keeps the reason codes in English', function () {
    expect(RuleException::overLimit('daily', 0, 'USD')->reason)->toBe('over_daily_limit')
        ->and(RuleException::outsideHours([])->reason)->toBe('outside_hours')
        ->and(SaleException::outOfStock('Cola', 0)->reason)->toBe('out_of_stock');
});

// ------------------------------------------------------------ Khmer, whole sentences

it('says it all in Khmer, with no English word left in the sentence', function () {
    $stock = ts5Khmer(fn () => SaleException::outOfStock('Cola', 0)->getMessage());
    $limit = ts5Khmer(fn () => RuleException::overLimit('daily', 150, 'USD')->getMessage());
    $max = ts5Khmer(fn () => RuleException::limitAboveSchoolMax('weekly', 5000, 'USD')->getMessage());
    $hours = ts5Khmer(fn () => RuleException::outsideHours([['08:00', '10:00']])->getMessage());

    expect($stock)->toContain('Cola')->not->toContain('out of stock')
        ->and($limit)->toContain('$1.50')->not->toContain('Daily')->not->toContain('today')
        ->and($max)->toContain('ប្រចាំសប្តាហ៍')->not->toContain('weekly')
        ->and($hours)->toContain('08:00–10:00')->not->toContain('Buying hours');
});

it('shows the role in Khmer in the staff messages', function () {
    $school = ts5School();
    $admin = ts5User($school, 'admin');
    $cashier = ts5User($school, 'cashier');

    $this->actingAs($admin)->post('/locale', ['locale' => 'km']);
    $this->post("/admin/staff/{$cashier->id}/role", ['role' => 'manager'])->assertRedirect();

    $status = session('status');

    expect($status)->toContain(ts5Khmer(fn () => __('manager')))->not->toContain('manager');
});

it('shows the role in English when the person reads English', function () {
    $school = ts5School();
    $admin = ts5User($school, 'admin');
    $cashier = ts5User($school, 'cashier');

    $this->actingAs($admin)->post("/admin/staff/{$cashier->id}/role", ['role' => 'manager'])->assertRedirect();

    expect(session('status'))->toBe("{$cashier->name} is now manager.");
});

it('has Khmer for the role names, so a role shown on a screen is not English', function () {
    app()->setLocale('km');

    foreach (['Admin', 'Manager', 'Cashier', 'Parent', 'admin', 'manager', 'cashier', 'parent', 'super-admin', 'daily', 'weekly'] as $word) {
        expect(__($word))->not->toBe($word, "no Khmer for: {$word}");
    }

    app()->setLocale('en');
});

// ------------------------------------------------------ the browser sentences

it('has no sentence built from pieces left in the till and meal screen JavaScript', function () {
    $till = file_get_contents(resource_path('views/till/index.blade.php'));
    $meals = file_get_contents(resource_path('views/till/meals.blade.php'));

    expect($till)->toContain("__(':count left', {count: p.stock})")
        ->toContain("__('Grade :grade', {grade: res.student.grade})")
        ->toContain("__('Something went wrong (error :status). Please try again.', {status: xhr.status})")
        ->not->toContain("'Grade ' + ")
        ->and($meals)->toContain("__('Student number :code', {code: student.code})")
        ->toContain("__('Grade :grade', {grade: student.grade})")
        ->toContain(".text(__('Yes, record'))")
        ->toContain("'✕ ' + __('NO')")
        ->not->toContain("'Student number ' + ");
});

// ---------------------------------------------- the parents' messages, in their language

it('writes a parent\'s notification in the language that parent chose', function () {
    config(['messaging.push' => 'none', 'messaging.sms' => 'none']);

    $school = ts5School();
    $student = Student::create(['school_id' => $school->id, 'student_code' => 'N5001', 'name' => 'Sokha Chan', 'grade' => 3]);
    $flags = ['notify_topup' => true, 'notify_purchase' => true, 'notify_low_balance' => true];

    $khmerParent = ts5User($school, 'parent', ['locale' => 'km'] + $flags);
    $englishParent = ts5User($school, 'parent', ['locale' => 'en'] + $flags);
    $defaultParent = ts5User($school, 'parent', $flags);   // chose nothing: the school's default (English here)

    foreach ([$khmerParent, $englishParent, $defaultParent] as $parent) {
        GuardianLink::create(['school_id' => $school->id, 'user_id' => $parent->id, 'student_id' => $student->id]);
    }

    app(LedgerService::class)->topUp($student->account, 1000, 'ts5-topup');

    $message = fn (User $parent) => ParentMessage::withoutGlobalScopes()->where('user_id', $parent->id)->first();

    $khmerTitle = ts5Khmer(fn () => __('Top-up received'));

    expect($message($khmerParent)->title)->toBe($khmerTitle)->not->toBe('Top-up received')
        ->and($message($khmerParent)->body)->toContain('Sokha')->toContain('$10.00')->not->toContain('added')
        ->and($message($englishParent)->title)->toBe('Top-up received')
        ->and($message($englishParent)->body)->toBe('Sokha: $10.00 added. Balance: $10.00.')
        ->and($message($defaultParent)->title)->toBe('Top-up received');
});

it('puts the language back after writing a notification', function () {
    config(['messaging.push' => 'none', 'messaging.sms' => 'none']);

    $school = ts5School();
    $student = Student::create(['school_id' => $school->id, 'student_code' => 'N5002', 'name' => 'Dara Lim', 'grade' => 3]);
    $parent = ts5User($school, 'parent', ['locale' => 'km', 'notify_topup' => true]);
    GuardianLink::create(['school_id' => $school->id, 'user_id' => $parent->id, 'student_id' => $student->id]);

    app()->setLocale('en');
    app(LedgerService::class)->topUp($student->account, 500, 'ts5-restore');

    expect(app()->getLocale())->toBe('en');
});
