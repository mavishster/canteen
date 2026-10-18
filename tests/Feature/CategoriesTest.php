<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentBan;
use App\Models\User;
use Spatie\Permission\Models\Role;

function catAdmin(School $school, string $role = 'admin'): User
{
    Role::findOrCreate($role, 'web');

    $user = User::factory()->create(['school_id' => $school->id]);
    $user->assignRole($role);

    return $user;
}

function catSchool(string $code = 'CAT'): School
{
    return School::firstOrCreate(['code' => $code], ['name' => "School {$code}"]);
}

function catMake(School $school, string $name): Category
{
    return Category::create(['school_id' => $school->id, 'name' => $name]);
}

// ----------------------------------------------------------------- the list

it('adds a category', function () {
    $school = catSchool();
    $this->actingAs(catAdmin($school));

    $this->post('/admin/categories', ['name' => '  Hot   drinks '])->assertRedirect(route('admin.categories'));

    expect(Category::first()->name)->toBe('Hot drinks')
        ->and(Category::first()->refresh()->is_active)->toBeTrue();
});

it('refuses a name that is only another way of writing an existing category', function (string $typed) {
    $school = catSchool();
    catMake($school, 'Snacks');
    $this->actingAs(catAdmin($school));

    $this->post('/admin/categories', ['name' => $typed])->assertSessionHasErrors('name');

    expect(Category::count())->toBe(1);
})->with(['snack', 'SNACKS', '  Snacks  ', 'Snack-s', 'Snacks!', 'snacks ']);

it('allows genuinely different categories', function () {
    $school = catSchool();
    catMake($school, 'Snacks');
    $this->actingAs(catAdmin($school));

    $this->post('/admin/categories', ['name' => 'Drinks'])->assertRedirect();
    $this->post('/admin/categories', ['name' => 'Hot drinks'])->assertRedirect();

    expect(Category::count())->toBe(3);
});

it('treats the same name in another school as a different category', function () {
    catMake(catSchool('OTHERCAT'), 'Snacks');
    $mine = catSchool();
    $this->actingAs(catAdmin($mine));

    $this->post('/admin/categories', ['name' => 'Snacks'])->assertRedirect();

    expect(Category::withoutGlobalScopes()->where('school_id', $mine->id)->count())->toBe(1);
});

it('renames a category, but not into another one\'s name', function () {
    $school = catSchool();
    $snacks = catMake($school, 'Snacks');
    catMake($school, 'Drinks');
    $this->actingAs(catAdmin($school));

    $this->post("/admin/categories/{$snacks->id}/rename", ['name' => 'Snack bar'])->assertRedirect();
    expect($snacks->refresh()->name)->toBe('Snack bar');

    $this->post("/admin/categories/{$snacks->id}/rename", ['name' => 'drink'])->assertSessionHasErrors('name');
    expect($snacks->refresh()->name)->toBe('Snack bar');

    // changing only the capital letters of its own name is fine
    $this->post("/admin/categories/{$snacks->id}/rename", ['name' => 'SNACK BAR'])->assertRedirect();
    expect($snacks->refresh()->name)->toBe('SNACK BAR');
});

it('hides and shows a category', function () {
    $school = catSchool();
    $drinks = catMake($school, 'Drinks');
    $this->actingAs(catAdmin($school));

    $this->post("/admin/categories/{$drinks->id}/toggle")->assertRedirect();
    expect($drinks->refresh()->is_active)->toBeFalse();

    $this->post("/admin/categories/{$drinks->id}/toggle");
    expect($drinks->refresh()->is_active)->toBeTrue();
});

// -------------------------------------------------------------------- merging

it('merges a duplicate into the right category, moving products and bans', function () {
    $school = catSchool();
    $good = catMake($school, 'Snacks');
    $typo = catMake($school, 'Snak');
    $one = Product::create(['school_id' => $school->id, 'name' => 'Biscuits', 'price' => 50, 'category_id' => $typo->id]);
    $two = Product::create(['school_id' => $school->id, 'name' => 'Crisps', 'price' => 60, 'category_id' => $typo->id]);

    $alice = Student::create(['school_id' => $school->id, 'student_code' => 'A', 'name' => 'Alice']);
    $bob = Student::create(['school_id' => $school->id, 'student_code' => 'B', 'name' => 'Bob']);
    StudentBan::create(['school_id' => $school->id, 'student_id' => $alice->id, 'category_id' => $typo->id]);
    StudentBan::create(['school_id' => $school->id, 'student_id' => $bob->id, 'category_id' => $typo->id]);
    StudentBan::create(['school_id' => $school->id, 'student_id' => $bob->id, 'category_id' => $good->id]);   // already banned from the good one

    $this->actingAs(catAdmin($school));
    $this->post("/admin/categories/{$typo->id}/merge", ['into_id' => $good->id])->assertRedirect(route('admin.categories'));

    expect(Category::find($typo->id))->toBeNull()
        ->and($one->refresh()->category_id)->toBe($good->id)
        ->and($two->refresh()->category_id)->toBe($good->id)
        ->and(StudentBan::where('category_id', $good->id)->count())->toBe(2)   // Alice moved, Bob kept one
        ->and(StudentBan::where('student_id', $bob->id)->count())->toBe(1);
});

it('refuses to merge a category into itself or into one that does not exist', function () {
    $school = catSchool();
    $snacks = catMake($school, 'Snacks');
    $this->actingAs(catAdmin($school));

    $this->post("/admin/categories/{$snacks->id}/merge", ['into_id' => $snacks->id])->assertSessionHasErrors('category');
    $this->post("/admin/categories/{$snacks->id}/merge", ['into_id' => 999999])->assertSessionHasErrors('category');

    expect(Category::find($snacks->id))->not->toBeNull();
});

it('cannot merge into a category of another school', function () {
    $mine = catSchool();
    $theirs = catMake(catSchool('OTHERCAT'), 'Snacks');
    $typo = catMake($mine, 'Snak');
    $this->actingAs(catAdmin($mine));

    $this->post("/admin/categories/{$typo->id}/merge", ['into_id' => $theirs->id])->assertSessionHasErrors('category');

    expect(Category::find($typo->id))->not->toBeNull();
});

// ------------------------------------------------------------------ deleting

it('deletes an unused category but never one that is in use', function () {
    $school = catSchool();
    $empty = catMake($school, 'Empty');
    $used = catMake($school, 'Used');
    $banned = catMake($school, 'Banned');
    Product::create(['school_id' => $school->id, 'name' => 'Cola', 'price' => 100, 'category_id' => $used->id]);
    $kid = Student::create(['school_id' => $school->id, 'student_code' => 'K', 'name' => 'Kid']);
    StudentBan::create(['school_id' => $school->id, 'student_id' => $kid->id, 'category_id' => $banned->id]);
    $this->actingAs(catAdmin($school));

    $this->post("/admin/categories/{$empty->id}/delete")->assertRedirect();
    $this->post("/admin/categories/{$used->id}/delete")->assertSessionHasErrors('category');
    $this->post("/admin/categories/{$banned->id}/delete")->assertSessionHasErrors('category');

    expect(Category::find($empty->id))->toBeNull()
        ->and(Category::find($used->id))->not->toBeNull()
        ->and(Category::find($banned->id))->not->toBeNull();
});

// ----------------------------------------------------- products use the list

it('shows the counts on the categories page', function () {
    $school = catSchool();
    $snacks = catMake($school, 'Snacks');
    Product::create(['school_id' => $school->id, 'name' => 'Crisps', 'price' => 60, 'category_id' => $snacks->id]);
    $this->actingAs(catAdmin($school));

    $this->get('/admin/categories')->assertOk()->assertSee('Snacks');
});

it('lets a product pick only an available category of its own school', function () {
    $school = catSchool();
    $good = catMake($school, 'Snacks');
    $hidden = catMake($school, 'Old');
    $hidden->update(['is_active' => false]);
    $foreign = catMake(catSchool('OTHERCAT'), 'Foreign');
    $this->actingAs(catAdmin($school));

    $form = ['name' => 'Crisps', 'price' => '0.60'];

    $this->post('/admin/products', $form + ['category_id' => $hidden->id])->assertSessionHasErrors('category_id');
    $this->post('/admin/products', $form + ['category_id' => $foreign->id])->assertSessionHasErrors('category_id');
    $this->post('/admin/products', $form + ['category_id' => 999999])->assertSessionHasErrors('category_id');
    expect(Product::count())->toBe(0);

    $this->post('/admin/products', $form + ['category_id' => $good->id])->assertRedirect();
    expect(Product::first()->category_id)->toBe($good->id);
});

it('no longer creates a category from typed text', function () {
    $school = catSchool();
    $this->actingAs(catAdmin($school));

    $this->post('/admin/products', ['name' => 'Crisps', 'price' => '0.60', 'category' => 'Snak'])->assertRedirect();

    expect(Product::first()->category_id)->toBeNull()
        ->and(Category::count())->toBe(0);
});

it('changes or clears the category of an existing product', function () {
    $school = catSchool();
    $snacks = catMake($school, 'Snacks');
    $drinks = catMake($school, 'Drinks');
    $product = Product::create(['school_id' => $school->id, 'name' => 'Crisps', 'price' => 60, 'category_id' => $snacks->id]);
    $this->actingAs(catAdmin($school));

    $this->post("/admin/products/{$product->id}/category", ['category_id' => $drinks->id])->assertRedirect();
    expect($product->refresh()->category_id)->toBe($drinks->id);

    $this->post("/admin/products/{$product->id}/category", ['category_id' => ''])->assertRedirect();
    expect($product->refresh()->category_id)->toBeNull();
});

it('refuses a category for a product that is not in the product\'s school', function () {
    $school = catSchool();
    $product = Product::create(['school_id' => $school->id, 'name' => 'Crisps', 'price' => 60]);
    $foreign = catMake(catSchool('OTHERCAT'), 'Foreign');
    $this->actingAs(catAdmin($school));

    $this->post("/admin/products/{$product->id}/category", ['category_id' => $foreign->id])->assertSessionHasErrors('category_id');
});

// ------------------------------------------------------------------- limits

it('cannot touch another school\'s categories', function () {
    $mine = catSchool();
    $theirs = catMake(catSchool('OTHERCAT'), 'Snacks');
    $this->actingAs(catAdmin($mine));

    $this->post("/admin/categories/{$theirs->id}/rename", ['name' => 'Hacked'])->assertNotFound();
    $this->post("/admin/categories/{$theirs->id}/toggle")->assertNotFound();
    $this->post("/admin/categories/{$theirs->id}/delete")->assertNotFound();

    expect($theirs->refresh()->name)->toBe('Snacks');
});

it('keeps managers and cashiers out of the categories list', function () {
    $school = catSchool();

    $this->actingAs(catAdmin($school, 'manager'))->get('/admin/categories')->assertForbidden();
    $this->actingAs(catAdmin($school, 'cashier'))->get('/admin/categories')->assertForbidden();
});
