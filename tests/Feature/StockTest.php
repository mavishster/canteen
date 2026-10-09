<?php

use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\SaleException;
use App\Models\Product;
use App\Models\Sale;
use App\Models\School;
use App\Models\Student;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\CardService;
use App\Services\LedgerService;
use App\Services\SaleService;
use App\Services\StockService;
use Spatie\Permission\Models\Role;

/** @return array{0: School, 1: Student, 2: string} */
function stockSetup(int $balance = 100000): array
{
    static $n = 0;
    $n++;

    $school = School::create(['name' => "Stock School {$n}", 'code' => "STK{$n}"]);
    $student = Student::create([
        'school_id' => $school->id,
        'student_code' => "ST{$n}",
        'name' => "Stock Kid {$n}",
    ]);

    $uid = strtoupper(dechex(0x30000000 + $n));
    app(CardService::class)->bind($student, $uid);
    app(LedgerService::class)->topUp($student->account, $balance, "stock-seed-{$n}");

    return [$school, $student, $uid];
}

/** $stock = null means the product is not tracked */
function stockProduct(School $school, string $name, int $price, ?int $stock = null): Product
{
    return Product::create([
        'school_id' => $school->id,
        'name' => $name,
        'price' => $price,
        'track_stock' => $stock !== null,
        'stock' => $stock ?? 0,
    ]);
}

function stockSell(School $school, string $uid, Product $product, int $qty, string $key)
{
    return app(SaleService::class)->checkout($school->id, null, $uid, [
        ['product_id' => $product->id, 'quantity' => $qty],
    ], $key);
}

function stockReasonOf(callable $fn): ?string
{
    try {
        $fn();
    } catch (SaleException $e) {
        return $e->reason;
    }

    return null;
}

function stockAdmin(School $school, string $role = 'admin'): User
{
    Role::findOrCreate($role, 'web');

    $user = User::factory()->create(['school_id' => $school->id]);
    $user->assignRole($role);

    return $user;
}

// ------------------------------------------------------------ selling

it('deducts stock and the balance together and records the movement', function () {
    [$school, $student, $uid] = stockSetup(10000);
    $cola = stockProduct($school, 'Cola', 350, stock: 10);

    stockSell($school, $uid, $cola, 2, 'k1');

    $movement = StockMovement::where('product_id', $cola->id)->first();

    expect($cola->refresh()->stock)->toBe(8)
        ->and($student->account->refresh()->balance)->toBe(9300)
        ->and($movement->type)->toBe('sale')
        ->and($movement->quantity)->toBe(-2)
        ->and($movement->stock_after)->toBe(8)
        ->and($movement->reference)->toBe('sale:k1');
});

it('leaves products that are not tracked alone', function () {
    [$school, , $uid] = stockSetup();
    $meal = stockProduct($school, 'Fried rice', 500);   // not tracked

    stockSell($school, $uid, $meal, 3, 'k1');

    expect($meal->refresh()->stock)->toBe(0)
        ->and(StockMovement::count())->toBe(0)
        ->and(Sale::count())->toBe(1);
});

it('refuses a sale when there is not enough stock and changes nothing', function () {
    [$school, $student, $uid] = stockSetup(10000);
    $cola = stockProduct($school, 'Cola', 350, stock: 1);

    expect(stockReasonOf(fn () => stockSell($school, $uid, $cola, 2, 'k1')))->toBe('out_of_stock');

    expect($cola->refresh()->stock)->toBe(1)
        ->and($student->account->refresh()->balance)->toBe(10000)
        ->and(Sale::count())->toBe(0)
        ->and(StockMovement::count())->toBe(0);
});

it('lets the last item be sold and then refuses the next one', function () {
    [$school, , $uid] = stockSetup(10000);
    $cola = stockProduct($school, 'Cola', 350, stock: 1);

    stockSell($school, $uid, $cola, 1, 'k1');

    expect($cola->refresh()->stock)->toBe(0)
        ->and(stockReasonOf(fn () => stockSell($school, $uid, $cola, 1, 'k2')))->toBe('out_of_stock');
});

it('counts the same product added twice as one line against the stock', function () {
    [$school, , $uid] = stockSetup(10000);
    $cola = stockProduct($school, 'Cola', 350, stock: 4);

    $reason = stockReasonOf(fn () => app(SaleService::class)->checkout($school->id, null, $uid, [
        ['product_id' => $cola->id, 'quantity' => 2],
        ['product_id' => $cola->id, 'quantity' => 3],
    ], 'k1'));

    expect($reason)->toBe('out_of_stock')
        ->and($cola->refresh()->stock)->toBe(4);
});

it('puts the stock back when the balance is too low', function () {
    [$school, $student, $uid] = stockSetup(100);
    $rice = stockProduct($school, 'Fried rice', 500, stock: 10);

    expect(fn () => stockSell($school, $uid, $rice, 1, 'k1'))->toThrow(InsufficientBalanceException::class);

    expect($rice->refresh()->stock)->toBe(10)
        ->and($student->account->refresh()->balance)->toBe(100)
        ->and(StockMovement::count())->toBe(0);
});

it('does not deduct stock twice when the same sale is retried', function () {
    [$school, , $uid] = stockSetup(10000);
    $cola = stockProduct($school, 'Cola', 350, stock: 10);

    $first = stockSell($school, $uid, $cola, 1, 'same-key');
    $retry = stockSell($school, $uid, $cola, 1, 'same-key');

    expect($retry->id)->toBe($first->id)
        ->and($cola->refresh()->stock)->toBe(9)
        ->and(StockMovement::count())->toBe(1);
});

// ------------------------------------------------------------ deliveries & counts

it('adds delivered stock and records it', function () {
    [$school] = stockSetup();
    $cola = stockProduct($school, 'Cola', 350, stock: 10);

    $movement = app(StockService::class)->receive($cola, 5, null, 'Delivery');

    expect($cola->refresh()->stock)->toBe(15)
        ->and($movement->quantity)->toBe(5)
        ->and($movement->stock_after)->toBe(15)
        ->and($movement->note)->toBe('Delivery');
});

it('sets the stock to a counted number and records the difference', function () {
    [$school] = stockSetup();
    $cola = stockProduct($school, 'Cola', 350, stock: 10);

    $movement = app(StockService::class)->count($cola, 7);

    expect($cola->refresh()->stock)->toBe(7)
        ->and($movement->type)->toBe('adjustment')
        ->and($movement->quantity)->toBe(-3);
});

it('refuses stock changes on an untracked product and bad quantities', function () {
    [$school] = stockSetup();
    $meal = stockProduct($school, 'Fried rice', 500);
    $cola = stockProduct($school, 'Cola', 350, stock: 10);

    expect(fn () => app(StockService::class)->receive($meal, 5))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(StockService::class)->receive($cola, 0))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(StockService::class)->count($cola, -1))->toThrow(InvalidArgumentException::class);
});

it('never lets a stock movement be changed or deleted', function () {
    [$school] = stockSetup();
    $cola = stockProduct($school, 'Cola', 350, stock: 10);
    $movement = app(StockService::class)->receive($cola, 5);

    expect(fn () => $movement->update(['quantity' => 99]))->toThrow(LogicException::class);
    expect(fn () => $movement->delete())->toThrow(LogicException::class);
});

// ------------------------------------------------------------ admin screens

it('turns stock tracking on and off from the products page', function () {
    $school = School::create(['name' => 'S1', 'code' => 'S1']);
    $cola = stockProduct($school, 'Cola', 350);
    $this->actingAs(stockAdmin($school));

    $this->post("/admin/products/{$cola->id}/tracking")->assertRedirect();
    expect($cola->refresh()->track_stock)->toBeTrue();

    $this->post("/admin/products/{$cola->id}/tracking");
    expect($cola->refresh()->track_stock)->toBeFalse();
});

it('receives stock and sets a count from the products page', function () {
    $school = School::create(['name' => 'S1', 'code' => 'S1']);
    $cola = stockProduct($school, 'Cola', 350, stock: 0);
    $admin = stockAdmin($school);
    $this->actingAs($admin);

    $this->post("/admin/products/{$cola->id}/stock/receive", ['quantity' => 24, 'note' => 'Monday delivery'])->assertRedirect();
    expect($cola->refresh()->stock)->toBe(24);

    $this->post("/admin/products/{$cola->id}/stock/count", ['counted' => 20])->assertRedirect();
    expect($cola->refresh()->stock)->toBe(20);

    $movement = StockMovement::where('product_id', $cola->id)->orderBy('id')->first();
    expect($movement->user_id)->toBe($admin->id)
        ->and($movement->note)->toBe('Monday delivery');
});

it('explains why stock cannot be received on an untracked product', function () {
    $school = School::create(['name' => 'S1', 'code' => 'S1']);
    $meal = stockProduct($school, 'Fried rice', 500);
    $this->actingAs(stockAdmin($school));

    $this->post("/admin/products/{$meal->id}/stock/receive", ['quantity' => 5])->assertSessionHasErrors('stock');
});

it('adds a product with opening stock', function () {
    $school = School::create(['name' => 'S1', 'code' => 'S1']);
    $this->actingAs(stockAdmin($school));

    $this->post('/admin/products', ['name' => 'Biscuits', 'price' => '0.75', 'stock' => '24'])->assertRedirect();

    $product = Product::where('name', 'Biscuits')->first();

    expect($product->track_stock)->toBeTrue()
        ->and($product->stock)->toBe(24)
        ->and(StockMovement::where('product_id', $product->id)->count())->toBe(1);
});

it('adds a product without stock tracking when the stock box is empty', function () {
    $school = School::create(['name' => 'S1', 'code' => 'S1']);
    $this->actingAs(stockAdmin($school));

    $this->post('/admin/products', ['name' => 'Fried rice', 'price' => '2.50', 'stock' => ''])->assertRedirect();

    expect(Product::where('name', 'Fried rice')->first()->track_stock)->toBeFalse();
});

it("cannot change another school's stock", function () {
    $mine = School::create(['name' => 'Mine', 'code' => 'MINE']);
    $other = School::create(['name' => 'Other', 'code' => 'OTHER']);
    $theirs = stockProduct($other, 'Cola', 350, stock: 10);
    $this->actingAs(stockAdmin($mine));

    $this->post("/admin/products/{$theirs->id}/stock/receive", ['quantity' => 5])->assertNotFound();

    expect($theirs->refresh()->stock)->toBe(10);
});

it('shows the stock history and keeps cashiers out', function () {
    $school = School::create(['name' => 'S1', 'code' => 'S1']);
    $cola = stockProduct($school, 'Cola', 350, stock: 10);
    app(StockService::class)->receive($cola, 5, null, 'Delivery');

    $this->actingAs(stockAdmin($school))->get('/admin/stock')->assertOk()->assertSee('Cola');
    $this->actingAs(stockAdmin($school, 'cashier'))->get('/admin/stock')->assertForbidden();
});

// ------------------------------------------------------------ the till

it('shows a clear message at the till when stock runs out', function () {
    [$school, , $uid] = stockSetup();
    $cola = stockProduct($school, 'Cola', 350, stock: 1);
    $this->actingAs(stockAdmin($school, 'cashier'));

    $this->postJson('/till/checkout', [
        'uid' => $uid,
        'key' => 'k1',
        'items' => [['product_id' => $cola->id, 'quantity' => 2]],
    ])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'out_of_stock')
        ->assertJsonPath('message', 'Cola: only 1 left.');
});

it('returns the fresh stock after a till sale', function () {
    [$school, , $uid] = stockSetup();
    $cola = stockProduct($school, 'Cola', 350, stock: 5);
    $this->actingAs(stockAdmin($school, 'cashier'));

    $this->postJson('/till/checkout', [
        'uid' => $uid,
        'key' => 'k1',
        'items' => [['product_id' => $cola->id, 'quantity' => 2]],
    ])
        ->assertOk()
        ->assertJsonPath("stock.{$cola->id}", 3);
});

it('sends stock details to the till page', function () {
    [$school] = stockSetup();
    stockProduct($school, 'Cola', 350, stock: 3);
    $this->actingAs(stockAdmin($school, 'cashier'));

    $this->get('/till')->assertOk()->assertSee('"track_stock":true', false)->assertSee('"stock":3', false);
});
