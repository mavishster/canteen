<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\School;
use App\Models\User;
use App\Support\Money;
use Spatie\Permission\Models\Role;

function productAdmin(School $school, string $role = 'admin'): User
{
    Role::findOrCreate($role, 'web');

    $user = User::factory()->create(['school_id' => $school->id]);
    $user->assignRole($role);

    return $user;
}

it('converts typed prices to minor units', function () {
    expect(Money::toMinor('1.50', 'USD'))->toBe(150)
        ->and(Money::toMinor('19.99', 'USD'))->toBe(1999)
        ->and(Money::toMinor('0.07', 'USD'))->toBe(7)
        ->and(Money::toMinor('2500', 'KHR'))->toBe(2500);
});

it('adds a product with its category, storing the price in cents', function () {
    $school = School::create(['name' => 'S1', 'code' => 'S1', 'currency' => 'USD']);
    $this->actingAs(productAdmin($school));

    $this->post('/admin/products', ['name' => 'Cola', 'category' => 'Drinks', 'price' => '1.50'])
        ->assertRedirect(route('admin.products'));

    $product = Product::where('name', 'Cola')->first();

    expect($product->price)->toBe(150)
        ->and($product->category->name)->toBe('Drinks')
        ->and(Category::count())->toBe(1);
});

it('stores riel prices as whole riel', function () {
    $school = School::create(['name' => 'S1', 'code' => 'S1', 'currency' => 'KHR']);
    $this->actingAs(productAdmin($school));

    $this->post('/admin/products', ['name' => 'Water', 'price' => '2500']);

    expect(Product::where('name', 'Water')->first()->price)->toBe(2500);
});

it('updates a price and hides or shows a product', function () {
    $school = School::create(['name' => 'S1', 'code' => 'S1']);
    $product = Product::create(['school_id' => $school->id, 'name' => 'Cola', 'price' => 150]);
    $this->actingAs(productAdmin($school));

    $this->post("/admin/products/{$product->id}", ['price' => '2.25'])->assertRedirect();
    expect($product->refresh()->price)->toBe(225);

    $this->post("/admin/products/{$product->id}/toggle")->assertRedirect();
    expect($product->refresh()->is_active)->toBeFalse();

    $this->post("/admin/products/{$product->id}/toggle");
    expect($product->refresh()->is_active)->toBeTrue();
});

it('rejects a zero or negative price', function () {
    $school = School::create(['name' => 'S1', 'code' => 'S1']);
    $this->actingAs(productAdmin($school));

    $this->post('/admin/products', ['name' => 'Free', 'price' => '0'])->assertSessionHasErrors('price');

    expect(Product::count())->toBe(0);
});

it("cannot change another school's product", function () {
    $mine = School::create(['name' => 'Mine', 'code' => 'MINE']);
    $other = School::create(['name' => 'Other', 'code' => 'OTHER']);
    $theirs = Product::create(['school_id' => $other->id, 'name' => 'Cola', 'price' => 150]);

    $this->actingAs(productAdmin($mine));

    $this->post("/admin/products/{$theirs->id}", ['price' => '9.99'])->assertNotFound();
    expect($theirs->refresh()->price)->toBe(150);
});

it('keeps cashiers out of the products admin', function () {
    $school = School::create(['name' => 'S1', 'code' => 'S1']);

    $this->actingAs(productAdmin($school, 'cashier'))->get('/admin/products')->assertForbidden();
});
