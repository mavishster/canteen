<?php

use App\Exceptions\SaleException;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\Sale;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Services\CardService;
use App\Services\LedgerService;
use App\Services\SaleService;
use Carbon\Carbon;
use Spatie\Permission\Models\Role;

function chrAt(string $when): Carbon
{
    return Carbon::parse($when, 'Asia/Phnom_Penh');   // 2026-10-12 is a Monday, 2026-10-17 a Saturday
}

function chrUser(School $school, string $role): User
{
    Role::findOrCreate($role, 'web');

    $user = User::factory()->create(['school_id' => $school->id]);
    $user->assignRole($role);

    return $user;
}

/** @return array{0: School, 1: Category, 2: Product, 3: string} school, a restricted-able category, a product in it, a card number */
function chrSetup(array $category = []): array
{
    static $n = 0;
    $n++;

    $school = School::create(['name' => "Hours School {$n}", 'code' => "CHR{$n}"]);
    $snacks = Category::create(['school_id' => $school->id, 'name' => 'Snacks'] + $category);
    $crisps = Product::create(['school_id' => $school->id, 'category_id' => $snacks->id, 'name' => 'Crisps', 'price' => 100]);

    $student = Student::create(['school_id' => $school->id, 'student_code' => (string) (600000 + $n), 'name' => "Hours Kid {$n}", 'grade' => 3]);
    $uid = strtoupper(dechex(0xA0000000 + $n));
    app(CardService::class)->bind($student, $uid);
    app(LedgerService::class)->topUp($student->account, 10000, "chr-seed-{$n}");

    return [$school, $snacks, $crisps, $uid];
}

function chrSell(School $school, string $uid, array $products, string $key)
{
    $items = array_map(fn (Product $p) => ['product_id' => $p->id, 'quantity' => 1], $products);

    return app(SaleService::class)->checkout($school->id, null, $uid, $items, $key);
}

function chrReasonOf(callable $fn): ?array
{
    try {
        $fn();
    } catch (SaleException $e) {
        return ['reason' => $e->reason, 'message' => $e->getMessage()];
    }

    return null;
}

// --------------------------------------------------------------- the rule

it('sells a restricted category inside its window and refuses it outside', function () {
    [$school, , $crisps, $uid] = chrSetup(['sell_from' => '12:00', 'sell_to' => '13:00']);

    $this->travelTo(chrAt('2026-10-12 11:59'));
    $refused = chrReasonOf(fn () => chrSell($school, $uid, [$crisps], 'k1'));
    expect($refused['reason'])->toBe('category_closed')
        ->and($refused['message'])->toBe('Snacks can only be bought between 12:00 and 13:00.');

    $this->travelTo(chrAt('2026-10-12 12:00'));          // the start is included
    expect(chrSell($school, $uid, [$crisps], 'k2')->total)->toBe(100);

    $this->travelTo(chrAt('2026-10-12 13:00'));          // the end is included
    expect(chrSell($school, $uid, [$crisps], 'k3')->total)->toBe(100);

    $this->travelTo(chrAt('2026-10-12 13:01'));
    expect(chrReasonOf(fn () => chrSell($school, $uid, [$crisps], 'k4'))['reason'])->toBe('category_closed');
});

it('refuses a restricted category on a day it is not sold', function () {
    [$school, , $crisps, $uid] = chrSetup(['sell_days' => [1, 2, 3, 4, 5]]);

    $this->travelTo(chrAt('2026-10-12 12:00'));
    expect(chrSell($school, $uid, [$crisps], 'k1')->total)->toBe(100);

    $this->travelTo(chrAt('2026-10-17 12:00'));          // Saturday
    $refused = chrReasonOf(fn () => chrSell($school, $uid, [$crisps], 'k2'));

    expect($refused['reason'])->toBe('category_closed')
        ->and($refused['message'])->toBe('Snacks can only be bought on Mon, Tue, Wed, Thu, Fri.');
});

it('combines a time window with days', function () {
    [$school, , $crisps, $uid] = chrSetup(['sell_from' => '12:00', 'sell_to' => '13:00', 'sell_days' => [1, 3]]);

    $this->travelTo(chrAt('2026-10-12 12:30'));          // Monday, inside the window
    expect(chrSell($school, $uid, [$crisps], 'k1')->total)->toBe(100);

    $this->travelTo(chrAt('2026-10-13 12:30'));          // Tuesday, inside the window but not a selling day
    $refused = chrReasonOf(fn () => chrSell($school, $uid, [$crisps], 'k2'));

    expect($refused['message'])->toBe('Snacks can only be bought between 12:00 and 13:00 on Mon, Wed.');
});

it('never limits a category with nothing set, or a product with no category', function () {
    [$school, , $crisps, $uid] = chrSetup();             // no hours at all
    $plain = Product::create(['school_id' => $school->id, 'name' => 'Water', 'price' => 50]);   // no category

    $this->travelTo(chrAt('2026-10-17 03:00'));          // Saturday at night

    expect(chrSell($school, $uid, [$crisps], 'k1')->total)->toBe(100)
        ->and(chrSell($school, $uid, [$plain], 'k2')->total)->toBe(50);
});

it('refuses the whole sale when one item is closed, and charges nothing', function () {
    [$school, , $crisps, $uid] = chrSetup(['sell_from' => '12:00', 'sell_to' => '13:00']);
    $water = Product::create(['school_id' => $school->id, 'name' => 'Water', 'price' => 50]);
    $student = Student::withoutGlobalScopes()->where('school_id', $school->id)->first();

    $this->travelTo(chrAt('2026-10-12 10:00'));
    $refused = chrReasonOf(fn () => chrSell($school, $uid, [$water, $crisps], 'k1'));

    expect($refused['reason'])->toBe('category_closed')
        ->and(Sale::withoutGlobalScopes()->count())->toBe(0)
        ->and($student->account->refresh()->balance)->toBe(10000);

    expect(chrSell($school, $uid, [$water], 'k2')->total)->toBe(50);   // the open item alone is fine
});

it('names every closed category', function () {
    [$school, $snacks, $crisps, $uid] = chrSetup(['sell_from' => '12:00', 'sell_to' => '13:00']);
    $sweets = Category::create(['school_id' => $school->id, 'name' => 'Sweets', 'sell_from' => '14:00', 'sell_to' => '15:00']);
    $candy = Product::create(['school_id' => $school->id, 'category_id' => $sweets->id, 'name' => 'Candy', 'price' => 30]);

    $this->travelTo(chrAt('2026-10-12 10:00'));
    $refused = chrReasonOf(fn () => chrSell($school, $uid, [$crisps, $candy], 'k1'));

    expect($refused['message'])->toContain('Snacks can only be bought between 12:00 and 13:00.')
        ->toContain('Sweets can only be bought between 14:00 and 15:00.');
});

it('uses school time, not the server clock', function () {
    [$school, , $crisps, $uid] = chrSetup(['sell_from' => '12:00', 'sell_to' => '13:00']);

    // 05:30 UTC is 12:30 in Phnom Penh: inside the window, although it is early morning in UTC
    $this->travelTo(Carbon::parse('2026-10-12 05:30', 'UTC'));
    expect(chrSell($school, $uid, [$crisps], 'k1')->total)->toBe(100);

    // 12:30 UTC is 19:30 in Phnom Penh: outside the window
    $this->travelTo(Carbon::parse('2026-10-12 12:30', 'UTC'));
    expect(chrReasonOf(fn () => chrSell($school, $uid, [$crisps], 'k2'))['reason'])->toBe('category_closed');
});

it('shows the closed category message at the till', function () {
    [$school, , $crisps, $uid] = chrSetup(['sell_from' => '12:00', 'sell_to' => '13:00']);
    $this->travelTo(chrAt('2026-10-12 10:00'));
    $this->actingAs(chrUser($school, 'cashier'));

    $this->postJson('/till/checkout', [
        'uid' => $uid, 'key' => 'k1', 'items' => [['product_id' => $crisps->id, 'quantity' => 1]],
    ])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'category_closed')
        ->assertJsonPath('message', 'Snacks can only be bought between 12:00 and 13:00.');
});

// --------------------------------------------------------------- the admin page

it('saves selling hours and days for a category', function () {
    [$school, $snacks] = chrSetup();
    $this->actingAs(chrUser($school, 'admin'));

    $this->post("/admin/category-hours/{$snacks->id}", ['sell_from' => '12:00', 'sell_to' => '13:30', 'days' => ['5', '1', '3', '1']])
        ->assertRedirect(route('admin.category-hours'));

    $snacks->refresh();

    expect(substr($snacks->sell_from, 0, 5))->toBe('12:00')
        ->and(substr($snacks->sell_to, 0, 5))->toBe('13:30')
        ->and($snacks->sell_days)->toBe([1, 3, 5])
        ->and($snacks->hasSellingHours())->toBeTrue();

    $this->get('/admin/category-hours')->assertOk()->assertSee('Snacks')->assertSee('restricted');
});

it('treats all seven days as no day limit', function () {
    [$school, $snacks] = chrSetup();
    $this->actingAs(chrUser($school, 'admin'));

    $this->post("/admin/category-hours/{$snacks->id}", ['days' => ['1', '2', '3', '4', '5', '6', '7']])->assertRedirect();

    expect($snacks->refresh()->sell_days)->toBeNull()->and($snacks->hasSellingHours())->toBeFalse();
});

it('rejects half a window, a window that ends before it starts, and bad days', function () {
    [$school, $snacks] = chrSetup();
    $this->actingAs(chrUser($school, 'admin'));

    $this->post("/admin/category-hours/{$snacks->id}", ['sell_from' => '12:00'])->assertSessionHasErrors('category');
    $this->post("/admin/category-hours/{$snacks->id}", ['sell_from' => '13:00', 'sell_to' => '12:00'])->assertSessionHasErrors('category');
    $this->post("/admin/category-hours/{$snacks->id}", ['sell_from' => '12:00', 'sell_to' => '12:00'])->assertSessionHasErrors('category');
    $this->post("/admin/category-hours/{$snacks->id}", ['sell_from' => 'noon', 'sell_to' => '13:00'])->assertSessionHasErrors('sell_from');
    $this->post("/admin/category-hours/{$snacks->id}", ['days' => ['9']])->assertSessionHasErrors('days.0');

    expect($snacks->refresh()->hasSellingHours())->toBeFalse();
});

it('clears the selling hours of a category', function () {
    [$school, $snacks] = chrSetup(['sell_from' => '12:00', 'sell_to' => '13:00', 'sell_days' => [1, 2]]);
    $this->actingAs(chrUser($school, 'admin'));

    $this->post("/admin/category-hours/{$snacks->id}/clear")->assertRedirect(route('admin.category-hours'));

    $snacks->refresh();

    expect($snacks->sell_from)->toBeNull()->and($snacks->sell_to)->toBeNull()->and($snacks->sell_days)->toBeNull();
});

it('writes a change of selling hours to the audit log', function () {
    [$school, $snacks] = chrSetup();
    $this->actingAs(chrUser($school, 'admin'));

    $this->post("/admin/category-hours/{$snacks->id}", ['sell_from' => '12:00', 'sell_to' => '13:00'])->assertRedirect();

    $log = AuditLog::withoutGlobalScopes()->where('action', 'category.updated')->latest('id')->first();

    expect($log->summary)->toContain('sell from')->toContain('12:00');
});

it('cannot change another school\'s category, and keeps cashiers out', function () {
    [$mine] = chrSetup();
    [, $theirs] = chrSetup();

    $this->actingAs(chrUser($mine, 'admin'));
    $this->post("/admin/category-hours/{$theirs->id}", ['sell_from' => '12:00', 'sell_to' => '13:00'])->assertNotFound();
    $this->post("/admin/category-hours/{$theirs->id}/clear")->assertNotFound();

    expect($theirs->refresh()->hasSellingHours())->toBeFalse();

    $this->actingAs(chrUser($mine, 'cashier'))->get('/admin/category-hours')->assertForbidden();
    $this->actingAs(chrUser($mine, 'manager'))->get('/admin/category-hours')->assertForbidden();
});
