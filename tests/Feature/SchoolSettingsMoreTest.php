<?php

use App\Exceptions\SaleException;
use App\Exceptions\TopUpException;
use App\Models\Product;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Services\CardService;
use App\Services\LedgerService;
use App\Services\Notifications\LowBalance;
use App\Services\Payments\FakeGateway;
use App\Services\SaleService;
use App\Services\TopUpService;
use Spatie\Permission\Models\Role;

function set2User(School $school, string $role): User
{
    Role::findOrCreate($role, 'web');

    $user = User::factory()->create(['school_id' => $school->id]);
    $user->assignRole($role);

    return $user;
}

function set2School(array $attributes = []): School
{
    static $n = 0;
    $n++;

    return School::create($attributes + ['name' => "Settings School {$n}", 'code' => "SET{$n}"]);
}

/** @return array{0: Student, 1: string} a student with a card and $100 */
function set2Student(School $school): array
{
    static $n = 0;
    $n++;

    $student = Student::create(['school_id' => $school->id, 'student_code' => (string) (500000 + $n), 'name' => "Set{$n} Kid", 'grade' => 3]);
    $uid = strtoupper(dechex(0x90000000 + $n));
    app(CardService::class)->bind($student, $uid);
    app(LedgerService::class)->topUp($student->account, 10000, "set2-seed-{$n}");

    return [$student, $uid];
}

function set2Sell(School $school, string $uid, int $price, string $key)
{
    $product = Product::create(['school_id' => $school->id, 'name' => "Item {$key}", 'price' => $price]);

    return app(SaleService::class)->checkout($school->id, null, $uid, [['product_id' => $product->id, 'quantity' => 1]], $key);
}

// --------------------------------------------------------------- the page

it('shows every settings section with the figures in force', function () {
    $school = set2School(['currency' => 'USD']);
    $this->actingAs(set2User($school, 'admin'));

    $this->get('/admin/settings')
        ->assertOk()
        ->assertSee('Most one purchase may cost')
        ->assertSee('Smallest top-up')
        ->assertSee('Riel per US dollar')
        ->assertSee('Low balance warning')
        ->assertSee('selling by student number')
        ->assertSee('4100');   // the default exchange rate, shown as the real figure
});

it('saves the new settings in minor units and keeps everything else', function () {
    $school = set2School([
        'settings' => ['limits' => ['weekly' => [[1, 12, 5000]]], 'calendar' => ['closed_dates' => ['2026-12-25']]],
    ]);
    $this->actingAs(set2User($school, 'admin'));

    $this->post('/admin/settings', [
        'name' => 'Renamed School',
        'weekly' => ['50', '', '', ''],
        'max_sale' => '10',
        'topup_min' => '2',
        'khr_per_usd' => '4000',
        'low_balance' => '3',
        'manual_entry' => '0',
    ])->assertRedirect(route('admin.settings'));

    $school->refresh();

    expect($school->name)->toBe('Renamed School')
        ->and($school->settings['till']['max_sale'])->toBe(1000)
        ->and($school->settings['topup']['min'])->toBe(200)
        ->and($school->settings['topup']['khr_per_usd'])->toBe(4000)
        ->and($school->settings['notifications']['low_balance_default'])->toBe(300)
        ->and($school->settings['till']['manual_entry'])->toBeFalse()
        ->and($school->settings['limits']['weekly'])->toBe([[1, 3, 5000]])
        ->and($school->settings['calendar']['closed_dates'])->toBe(['2026-12-25']);
});

it('takes a blank box back to the default', function () {
    $school = set2School(['settings' => ['till' => ['max_sale' => 1000], 'topup' => ['min' => 500, 'khr_per_usd' => 4000]]]);
    $this->actingAs(set2User($school, 'admin'));

    $this->post('/admin/settings', ['max_sale' => '', 'topup_min' => '', 'khr_per_usd' => ''])->assertRedirect();

    $school->refresh();

    expect(data_get($school->settings, 'till.max_sale'))->toBeNull()
        ->and($school->minTopUp())->toBe(100)
        ->and($school->khrPerUsd())->toBe(4100);
});

it('leaves a setting alone when the form did not send it', function () {
    $school = set2School(['settings' => ['till' => ['manual_entry' => false, 'max_sale' => 1000]]]);
    $this->actingAs(set2User($school, 'admin'));

    $this->post('/admin/settings', ['weekly' => ['50', '', '', '']])->assertRedirect();

    $school->refresh();

    expect($school->settings['till']['manual_entry'])->toBeFalse()
        ->and($school->settings['till']['max_sale'])->toBe(1000);
});

it('stores riel figures as whole riel', function () {
    $school = set2School(['currency' => 'KHR']);
    $this->actingAs(set2User($school, 'admin'));

    $this->post('/admin/settings', ['max_sale' => '20000', 'topup_min' => '8000', 'low_balance' => '10000'])->assertRedirect();

    $school->refresh();

    expect($school->settings['till']['max_sale'])->toBe(20000)
        ->and($school->minTopUp())->toBe(8000)
        ->and($school->settings['notifications']['low_balance_default'])->toBe(10000);
});

it('rejects silly figures', function () {
    $school = set2School();
    $this->actingAs(set2User($school, 'admin'));

    $this->post('/admin/settings', ['khr_per_usd' => '500'])->assertSessionHasErrors('khr_per_usd');
    $this->post('/admin/settings', ['khr_per_usd' => '99999'])->assertSessionHasErrors('khr_per_usd');
    $this->post('/admin/settings', ['max_sale' => '-5'])->assertSessionHasErrors('max_sale');
    $this->post('/admin/settings', ['topup_min' => '0'])->assertSessionHasErrors('topup_min');
    $this->post('/admin/settings', ['low_balance' => '-1'])->assertSessionHasErrors('low_balance');
    $this->post('/admin/settings', ['name' => ''])->assertSessionHasErrors('name');
});

it('does not change the currency or time zone', function () {
    $school = set2School(['currency' => 'USD']);
    $this->actingAs(set2User($school, 'admin'));

    $this->post('/admin/settings', ['currency' => 'KHR', 'timezone' => 'UTC'])->assertRedirect();

    expect($school->refresh()->currency)->toBe('USD')
        ->and($school->timezone)->toBe('Asia/Phnom_Penh');
});

it('keeps managers and cashiers out of the settings', function () {
    $school = set2School();

    $this->actingAs(set2User($school, 'manager'))->post('/admin/settings', ['max_sale' => '1'])->assertForbidden();
    $this->actingAs(set2User($school, 'cashier'))->get('/admin/settings')->assertForbidden();
});

// --------------------------------------------- the settings really take effect

it('stops a single purchase above the limit, and allows exactly the limit', function () {
    $school = set2School();
    $school->update(['settings' => ['till' => ['max_sale' => 500]]]);
    [, $uid] = set2Student($school);

    try {
        set2Sell($school, $uid, 600, 'k1');
        $reason = null;
    } catch (SaleException $e) {
        $reason = $e->reason;
        $message = $e->getMessage();
    }

    expect($reason)->toBe('over_sale_limit')
        ->and($message)->toContain('$5.00');

    expect(set2Sell($school, $uid, 500, 'k2')->total)->toBe(500);
});

it('applies no single-purchase limit when none is set', function () {
    $school = set2School();
    [, $uid] = set2Student($school);

    expect(set2Sell($school, $uid, 9000, 'k1')->total)->toBe(9000);
});

it('shows the single-purchase limit at the till', function () {
    $school = set2School();
    $school->update(['settings' => ['till' => ['max_sale' => 500]]]);
    [$student, $uid] = set2Student($school);
    $product = Product::create(['school_id' => $school->id, 'name' => 'Big meal', 'price' => 900]);
    $this->actingAs(set2User($school, 'cashier'));

    $this->postJson('/till/checkout', [
        'uid' => $uid, 'key' => 'k1', 'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ])->assertStatus(422)->assertJsonPath('reason', 'over_sale_limit');

    expect($student->account->refresh()->balance)->toBe(10000);
});

it('uses the minimum top-up and the exchange rate from the settings', function () {
    $school = set2School();
    $this->actingAs(set2User($school, 'admin'));
    $this->post('/admin/settings', ['topup_min' => '2', 'khr_per_usd' => '4000'])->assertRedirect();

    $student = Student::create(['school_id' => $school->id, 'student_code' => 'TOPUP1', 'name' => 'Top Kid']);
    $topUps = app(TopUpService::class);
    $fake = app(FakeGateway::class);

    expect(fn () => $topUps->start($student, 150, 'USD', $fake))->toThrow(TopUpException::class);   // $1.50 is below $2

    $topup = $topUps->start($student, 41000, 'KHR', $fake);   // 41,000 riel at 4,000 = $10.25

    expect($topup->amount)->toBe(1025);
});

it('uses the low-balance default from the settings', function () {
    $school = set2School();
    $this->actingAs(set2User($school, 'admin'));

    $this->post('/admin/settings', ['low_balance' => '3'])->assertRedirect();

    expect(LowBalance::thresholdFor(null, $school->refresh()))->toBe(300);
});

it('switches selling by student number off and on from the page', function () {
    $school = set2School();
    [$student] = set2Student($school);
    $product = Product::create(['school_id' => $school->id, 'name' => 'Cola', 'price' => 100]);
    $admin = set2User($school, 'admin');
    $cashier = set2User($school, 'cashier');
    $items = [['product_id' => $product->id, 'quantity' => 1]];

    $this->actingAs($admin)->post('/admin/settings', ['manual_entry' => '0'])->assertRedirect();

    $this->actingAs($cashier)->postJson('/till/checkout', ['uid' => $student->student_code, 'key' => 'k1', 'items' => $items])
        ->assertStatus(422)->assertJsonPath('reason', 'manual_disabled');

    $this->actingAs($admin)->post('/admin/settings', ['manual_entry' => '1'])->assertRedirect();

    $this->actingAs($cashier)->postJson('/till/checkout', ['uid' => $student->student_code, 'key' => 'k2', 'items' => $items])
        ->assertOk()->assertJsonPath('confirm', true);
});
