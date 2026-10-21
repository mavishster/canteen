<?php

use App\Models\School;
use App\Models\User;
use App\Support\Menu;
use Spatie\Permission\Models\Role;

function mnSchool(): School
{
    static $n = 0;
    $n++;

    return School::create(['name' => "Menu School {$n}", 'code' => "MN{$n}"]);
}

function mnUser(?School $school, ?string $role): User
{
    $user = User::factory()->create(['school_id' => $school?->id]);

    if ($role) {
        Role::findOrCreate($role, 'web');
        $user->assignRole($role);
    }

    return $user->refresh();
}

/** @return array<int, array{label: string, url: string}> every link the person sees, dropdowns opened */
function mnLinks(User $user): array
{
    $links = [];

    foreach (app(Menu::class)->for($user) as $entry) {
        if ($entry['type'] === 'link') {
            $links[] = $entry;
        } else {
            array_push($links, ...$entry['items']);
        }
    }

    return $links;
}

function mnHas(User $user, string $routeName): bool
{
    return collect(mnLinks($user))->contains(fn ($link) => $link['url'] === route($routeName));
}

it('never shows a link the person is not allowed to open', function (string $role) {
    $user = mnUser(mnSchool(), $role);
    $links = mnLinks($user);

    expect($links)->not->toBeEmpty();

    foreach ($links as $link) {
        $status = $this->actingAs($user)->get($link['url'])->status();

        expect($status)->not->toBe(403, "{$role} was offered {$link['url']} but gets 403")
            ->and($status)->not->toBe(404);
    }
})->with(['cashier', 'manager', 'admin']);

it('gives a super-admin only the schools pages, all of which open', function () {
    $user = mnUser(null, 'super-admin');
    $links = mnLinks($user);

    expect($links)->not->toBeEmpty();

    foreach ($links as $link) {
        expect($link['url'])->toContain('/super/');
        expect($this->actingAs($user)->get($link['url'])->status())->not->toBe(403);
    }

    expect(mnHas($user, 'super.schools'))->toBeTrue()
        ->and(mnHas($user, 'admin.products'))->toBeFalse()
        ->and(mnHas($user, 'manager.reports.daily'))->toBeFalse();
});

it('shows an admin the admin and report pages but not the schools list', function () {
    $user = mnUser(mnSchool(), 'admin');

    expect(mnHas($user, 'admin.products'))->toBeTrue()
        ->and(mnHas($user, 'admin.audit'))->toBeTrue()
        ->and(mnHas($user, 'manager.reports.daily'))->toBeTrue()
        ->and(mnHas($user, 'super.schools'))->toBeFalse();
});

it('shows a manager the reports but not the admin pages', function () {
    $user = mnUser(mnSchool(), 'manager');

    expect(mnHas($user, 'manager.reports.daily'))->toBeTrue()
        ->and(mnHas($user, 'admin.products'))->toBeFalse()
        ->and(mnHas($user, 'admin.audit'))->toBeFalse()
        ->and(mnHas($user, 'super.schools'))->toBeFalse();
});

it('shows a cashier no admin, report or schools pages', function () {
    $user = mnUser(mnSchool(), 'cashier');

    foreach (mnLinks($user) as $link) {
        expect($link['url'])->not->toContain('/admin')->not->toContain('/manager')->not->toContain('/super');
    }
});

it('shows a person with no role nothing that needs a role', function () {
    $user = mnUser(mnSchool(), null);

    foreach (mnLinks($user) as $link) {
        expect($this->actingAs($user)->get($link['url'])->status())->not->toBe(403);
    }

    expect(mnHas($user, 'admin.products'))->toBeFalse()
        ->and(mnHas($user, 'manager.reports.daily'))->toBeFalse();
});

it('shows nothing to a guest', function () {
    expect(app(Menu::class)->for(null))->toBe([]);
});

it('groups admin pages under Admin and report pages under Reports', function () {
    $entries = app(Menu::class)->for(mnUser(mnSchool(), 'admin'));
    $groups = collect($entries)->where('type', 'group')->pluck('label')->all();

    expect($groups)->toContain('Admin')->toContain('Reports');
});

it('turns a dropdown with one link into that link', function () {
    config(['menu.items' => [['route' => 'admin.products', 'label' => 'Products']]]);

    $entries = app(Menu::class)->for(mnUser(mnSchool(), 'admin'));

    expect($entries)->toHaveCount(1)->and($entries[0]['type'])->toBe('link')->and($entries[0]['label'])->toBe('Products');
});

it('skips a route that does not exist', function () {
    config(['menu.items' => [['route' => 'no.such.page', 'label' => 'Nowhere'], ['route' => 'admin.products', 'label' => 'Products']]]);

    expect(mnLinks(mnUser(mnSchool(), 'admin')))->toHaveCount(1);
});

it('puts the menu on the page and marks the current one', function () {
    $admin = mnUser(mnSchool(), 'admin');
    $cashier = mnUser(mnSchool(), 'cashier');

    $this->actingAs($admin)->get(route('admin.products'))
        ->assertOk()
        ->assertSee('href="' . route('admin.audit') . '"', false)
        ->assertSee('dropdown-item active', false);

    $this->actingAs($cashier)->get('/')
        ->assertOk()
        ->assertDontSee('href="' . route('admin.products') . '"', false)
        ->assertDontSee('href="' . route('admin.audit') . '"', false);
});
