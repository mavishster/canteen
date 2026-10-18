<?php

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\GuardianLink;
use App\Models\MealPlan;
use App\Models\MealType;
use App\Models\Product;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentBan;
use App\Models\User;
use App\Services\CardService;
use App\Services\LedgerService;
use App\Services\MealSubscriptionService;
use App\Services\RefundService;
use App\Services\RuleService;
use App\Services\SaleService;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

function audUser(School $school, string $role): User
{
    Role::findOrCreate($role, 'web');

    $user = User::factory()->create(['school_id' => $school->id]);
    $user->assignRole($role);

    return $user;
}

/** @return array{0: School, 1: User} a school and a signed-in admin */
function audSetup(array $school = []): array
{
    static $n = 0;
    $n++;

    $school = School::create($school + ['name' => "Audit School {$n}", 'code' => "AUD{$n}"]);
    $admin = audUser($school, 'admin');

    test()->actingAs($admin);

    return [$school, $admin];
}

function audLogs(?string $action = null)
{
    return AuditLog::withoutGlobalScopes()
        ->when($action, fn ($q) => $q->where('action', $action))
        ->orderBy('id')
        ->get();
}

function audKid(School $school, string $code = 'K1'): Student
{
    return Student::create(['school_id' => $school->id, 'student_code' => $code, 'name' => "Kid {$code}", 'grade' => 3]);
}

// ----------------------------------------------------------------- what is recorded

it('records a price change with who did it and the before and after', function () {
    [$school, $admin] = audSetup();
    $product = Product::create(['school_id' => $school->id, 'name' => 'Cola', 'price' => 150]);

    $product->update(['price' => 175]);

    $log = audLogs('product.updated')->first();

    expect($log->actor_name)->toBe($admin->name)
        ->and($log->actor_role)->toBe('admin')
        ->and($log->school_id)->toEqual($school->id)
        ->and($log->subject_label)->toBe('Cola')
        ->and($log->summary)->toContain('$1.50')->toContain('$1.75')
        ->and($log->before)->toBe(['price' => 150])
        ->and($log->after)->toBe(['price' => 175]);
});

it('records a new product with its price', function () {
    [$school] = audSetup();

    Product::create(['school_id' => $school->id, 'name' => 'Cola', 'price' => 150]);

    expect(audLogs('product.created')->first()->summary)->toContain('Cola')->toContain('$1.50');
});

it('does not fill the log with stock changes or wallet balances', function () {
    [$school] = audSetup();
    $product = Product::create(['school_id' => $school->id, 'name' => 'Cola', 'price' => 150, 'track_stock' => true, 'stock' => 0]);
    $kid = audKid($school);
    $before = AuditLog::withoutGlobalScopes()->count();

    app(\App\Services\StockService::class)->receive($product, 10);
    app(LedgerService::class)->topUp($kid->account, 5000, 'aud-1');
    app(LedgerService::class)->purchase($kid->account, 100, 'aud-2');

    expect(AuditLog::withoutGlobalScopes()->count())->toBe($before);
});

it('records changes of category, school settings and meal plans', function () {
    [$school] = audSetup();

    $category = Category::create(['school_id' => $school->id, 'name' => 'Snak']);
    $category->update(['name' => 'Snacks']);
    $school->update(['settings' => ['till' => ['max_sale' => 500]]]);
    $type = MealType::create(['school_id' => $school->id, 'code' => 'lunch', 'name' => 'Lunch']);
    $plan = MealPlan::create(['school_id' => $school->id, 'meal_type_id' => $type->id, 'name' => 'Lunch 1 month', 'duration_months' => 1, 'price' => 3000]);
    $plan->update(['price' => 3500]);

    expect(audLogs('category.updated')->first()->summary)->toContain('Snak')->toContain('Snacks')
        ->and(audLogs('school.settings_changed')->first()->summary)->toContain('settings.till.max_sale')->toContain('$5.00')
        ->and(audLogs('meal_plan.updated')->first()->summary)->toContain('$30.00')->toContain('$35.00');
});

it('records spending limits but never the balance', function () {
    [$school] = audSetup();
    $kid = audKid($school);

    app(RuleService::class)->setLimits($kid, null, 1000);

    $log = audLogs('limits.changed')->first();

    expect($log->summary)->toContain('weekly limit')->toContain('$10.00')
        ->and($log->subject_label)->toBe('Kid K1');
});

it('records cards with the number masked, and bans', function () {
    [$school] = audSetup();
    $kid = audKid($school);
    $cards = app(CardService::class);

    $card = $cards->bind($kid, '0011300787');
    $cards->block($card);
    $cards->unblock($card);
    $product = Product::create(['school_id' => $school->id, 'name' => 'Cola', 'price' => 150]);
    $ban = app(RuleService::class)->banProduct($kid, $product);
    app(RuleService::class)->unban($ban);

    $everything = json_encode(AuditLog::withoutGlobalScopes()->get()->toArray());

    expect(audLogs('card.bound'))->toHaveCount(1)
        ->and(audLogs('card.blocked'))->toHaveCount(1)
        ->and(audLogs('card.unblocked'))->toHaveCount(1)
        ->and(audLogs('card.bound')->first()->summary)->toContain('…0787')
        ->and($everything)->not->toContain('0011300787')
        ->and(audLogs('ban.added')->first()->summary)->toContain('Cola')
        ->and(audLogs('ban.removed'))->toHaveCount(1);
});

it('records which parent blocked a card from the app', function () {
    [$school] = audSetup();
    $kid = audKid($school);
    app(CardService::class)->bind($kid, '0011300788');
    $parent = audUser($school, 'parent');
    GuardianLink::create(['school_id' => $school->id, 'user_id' => $parent->id, 'student_id' => $kid->id]);

    Sanctum::actingAs($parent);
    test()->postJson("/api/parent/children/{$kid->id}/card/block")->assertOk();

    $log = audLogs('card.blocked')->first();

    expect($log->actor_name)->toBe($parent->name)->and($log->actor_role)->toBe('parent');
});

it('records refunds being requested and approved', function () {
    [$school, $admin] = audSetup();
    $kid = audKid($school);
    app(LedgerService::class)->topUp($kid->account, 5000, 'aud-seed');
    $product = Product::create(['school_id' => $school->id, 'name' => 'Cola', 'price' => 150]);
    $sale = app(SaleService::class)->checkoutForStudent($school->id, $admin->id, $kid, [['product_id' => $product->id, 'quantity' => 1]], 'aud-sale');

    $refund = app(RefundService::class)->request($sale, $admin, 'Wrong item');
    app(RefundService::class)->approve($refund, $admin, 'OK');

    expect(audLogs('refund.requested')->first()->summary)->toContain("sale #{$sale->id}")->toContain('Wrong item')
        ->and(audLogs('refund.approved'))->toHaveCount(1);
});

it('records plans sold and staff changes, and never a password', function () {
    [$school, $admin] = audSetup();

    $this->post('/admin/staff', ['name' => 'Sok Cashier', 'email' => 'sok@example.test', 'role' => 'cashier', 'password' => 'a-long-password-1'])->assertRedirect();
    $sok = User::where('email', 'sok@example.test')->first();
    $this->post("/admin/staff/{$sok->id}/role", ['role' => 'manager'])->assertRedirect();
    $this->post("/admin/staff/{$sok->id}/password", ['password' => 'another-long-password-2'])->assertRedirect();
    $this->post("/admin/staff/{$sok->id}/disable")->assertRedirect();
    $this->post("/admin/staff/{$sok->id}/enable", ['role' => 'cashier', 'password' => 'a-third-long-password-3'])->assertRedirect();

    $everything = json_encode(AuditLog::withoutGlobalScopes()->get()->toArray());

    expect(audLogs('user.created')->where('subject_label', 'Sok Cashier'))->toHaveCount(1)
        ->and(audLogs('staff.role_assigned'))->toHaveCount(1)
        ->and(audLogs('staff.role_changed')->first()->summary)->toContain('cashier → manager')
        ->and(audLogs('user.password_changed')->where('subject_label', 'Sok Cashier'))->not->toBeEmpty()
        ->and(audLogs('staff.disabled')->first()->summary)->toContain('was manager')
        ->and(audLogs('staff.enabled'))->toHaveCount(1)
        ->and($everything)->not->toContain('a-long-password-1')
        ->and($everything)->not->toContain('another-long-password-2')
        ->and($everything)->not->toContain('$2y$');
});

it('records a meal plan sale', function () {
    [$school] = audSetup();
    $kid = audKid($school);
    app(LedgerService::class)->topUp($kid->account, 20000, 'aud-seed');
    $type = MealType::create(['school_id' => $school->id, 'code' => 'lunch', 'name' => 'Lunch']);
    $plan = MealPlan::create(['school_id' => $school->id, 'meal_type_id' => $type->id, 'name' => 'Lunch 1 month', 'duration_months' => 1, 'price' => 3000]);

    app(MealSubscriptionService::class)->purchase($kid, $plan->id, 'aud-plan');

    expect(audLogs('meal_plan.sold')->first()->summary)->toContain('Lunch 1 month')->toContain('Kid K1')->toContain('$30.00');
});

it('leaves out work done by the system with nobody behind it', function () {
    $school = School::create(['name' => 'System School', 'code' => 'SYSAUD']);

    Student::create(['school_id' => $school->id, 'student_code' => 'S1', 'name' => 'Imported Kid']);

    expect(audLogs('student.created'))->toHaveCount(0);

    $admin = audUser($school, 'admin');
    test()->actingAs($admin);
    Student::create(['school_id' => $school->id, 'student_code' => 'S2', 'name' => 'Added Kid']);

    expect(audLogs('student.created'))->toHaveCount(1);
});

it('records sign-ins and failed sign-ins without the password', function () {
    $school = School::create(['name' => 'Login School', 'code' => 'LOGAUD']);
    $user = audUser($school, 'cashier');
    $user->update(['password' => Hash::make('right-password-1')]);

    $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])->assertSessionHasErrors('email');
    $this->post('/login', ['email' => $user->email, 'password' => 'right-password-1'])->assertRedirect();

    $everything = json_encode(AuditLog::withoutGlobalScopes()->get()->toArray());

    expect(audLogs('auth.failed')->first()->summary)->toContain($user->email)
        ->and(audLogs('auth.login'))->toHaveCount(1)
        ->and($everything)->not->toContain('wrong-password')
        ->and($everything)->not->toContain('right-password-1');
});

it('writes the address of the request', function () {
    [$school] = audSetup();
    $product = Product::create(['school_id' => $school->id, 'name' => 'Cola', 'price' => 150]);

    $this->post("/admin/products/{$product->id}", ['price' => '2.00'])->assertRedirect();

    expect(audLogs('product.updated')->last()->ip)->toBe('127.0.0.1');
});

// ------------------------------------------------------------------- safety

it('can never be edited or deleted', function () {
    AuditRecorder::log('test.entry', 'test', 'x', 'A test entry');
    $log = audLogs('test.entry')->first();

    expect(fn () => $log->update(['summary' => 'changed']))->toThrow(LogicException::class);
    expect(fn () => $log->delete())->toThrow(LogicException::class);
});

it('never stops an action when the audit itself breaks', function () {
    [$school] = audSetup();
    app()->bind(AuditRecorder::class, fn () => new class extends AuditRecorder {
        public function modelEvent(string $event, \Illuminate\Database\Eloquent\Model $model): void
        {
            throw new RuntimeException('the audit is broken');
        }
    });

    $product = Product::create(['school_id' => $school->id, 'name' => 'Cola', 'price' => 150]);

    expect($product->exists)->toBeTrue()
        ->and(Product::count())->toBe(1);
});

// -------------------------------------------------------------------- the page

it('shows an admin the log of their own school only', function () {
    [$school] = audSetup();
    Product::create(['school_id' => $school->id, 'name' => 'My Cola', 'price' => 150]);

    $other = School::create(['name' => 'Other', 'code' => 'OTHERAUD']);
    $otherAdmin = audUser($other, 'admin');
    test()->actingAs($otherAdmin);
    Product::create(['school_id' => $other->id, 'name' => 'Their Secret Juice', 'price' => 99]);

    test()->actingAs(User::where('school_id', $school->id)->first());

    $this->get('/admin/audit')->assertOk()->assertSee('My Cola')->assertDontSee('Their Secret Juice');
});

it('lets a super-admin see every school', function () {
    [$school] = audSetup();
    Product::create(['school_id' => $school->id, 'name' => 'Cola One', 'price' => 150]);
    $other = School::create(['name' => 'Other', 'code' => 'OTHERAUD']);
    test()->actingAs(audUser($other, 'admin'));
    Product::create(['school_id' => $other->id, 'name' => 'Juice Two', 'price' => 99]);

    Role::findOrCreate('super-admin', 'web');
    $super = User::factory()->create(['school_id' => null]);
    $super->assignRole('super-admin');

    $this->actingAs($super)->get('/admin/audit')->assertOk()->assertSee('Cola One')->assertSee('Juice Two');
});

it('filters by what, who, date and words', function () {
    [$school, $admin] = audSetup();
    Product::create(['school_id' => $school->id, 'name' => 'Cola', 'price' => 150]);
    Category::create(['school_id' => $school->id, 'name' => 'Snacks']);

    $this->get('/admin/audit?entity=category')->assertOk()->assertSee('Snacks')->assertDontSee('Created product');
    $this->get('/admin/audit?user_id=' . $admin->id)->assertOk()->assertSee('Cola');
    $this->get('/admin/audit?q=Snacks')->assertOk()->assertSee('Snacks')->assertDontSee('Created product');
    $this->get('/admin/audit?from=2099-01-01')->assertOk()->assertSee('Nothing recorded');
    $this->get('/admin/audit?from=not-a-date')->assertSessionHasErrors('from');
});

it('exports the log as CSV and writes the export to the log', function () {
    [$school] = audSetup();
    Product::create(['school_id' => $school->id, 'name' => 'Cola', 'price' => 150]);

    $response = $this->get('/admin/audit.csv')->assertOk();
    $csv = $response->streamedContent();

    expect($csv)->toContain('time,who,role,action')->toContain('Cola')
        ->and(audLogs('report.exported'))->toHaveCount(1);
});

it('keeps managers and cashiers out of the audit log', function () {
    [$school] = audSetup();

    $this->actingAs(audUser($school, 'manager'))->get('/admin/audit')->assertForbidden();
    $this->actingAs(audUser($school, 'cashier'))->get('/admin/audit.csv')->assertForbidden();
});
