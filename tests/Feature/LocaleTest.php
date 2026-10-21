<?php

use App\Models\School;
use App\Models\User;
use App\Support\I18n\LocaleResolver;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

function lcUser(string $role = 'admin', array $attributes = []): User
{
    static $n = 0;
    $n++;

    $school = School::create(['name' => "Locale School {$n}", 'code' => "LC{$n}"]);
    Role::findOrCreate($role, 'web');

    $user = User::factory()->create(['school_id' => $school->id] + $attributes);
    $user->assignRole($role);

    return $user->refresh();
}

// ------------------------------------------------------------ choosing a language

it('answers in English when nobody chose anything', function () {
    $this->actingAs(lcUser())->get('/')->assertOk()->assertSee('<html lang="en"', false);
});

it('switches language from the menu and remembers it for the person', function () {
    $user = lcUser();
    $this->actingAs($user);

    $this->from('/')->post('/locale', ['locale' => 'km'])->assertRedirect('/')->assertCookie('locale');

    expect($user->refresh()->locale)->toBe('km');

    $this->get('/')->assertOk()->assertSee('<html lang="km"', false);
});

it('shows the Khmer text for the menu', function () {
    $this->actingAs(lcUser())->post('/locale', ['locale' => 'km']);

    $this->get(route('admin.products'))->assertOk()->assertSee('ការកំណត់');   // "Settings"
});

it('offers the switch in the menu', function () {
    $this->actingAs(lcUser())->get('/')->assertOk()->assertSee(route('locale.set'), false)->assertSee('ខ្មែរ');
});

it('lets someone who has not signed in switch language', function () {
    $this->post('/locale', ['locale' => 'km'])->assertRedirect();
    $this->get('/login')->assertOk()->assertSee('<html lang="km"', false);
});

it('accepts ?lang= on a page and remembers it for the session', function () {
    $this->get('/login?lang=km')->assertOk()->assertSee('<html lang="km"', false);
    $this->get('/login')->assertOk()->assertSee('<html lang="km"', false);
});

it('ignores a language it does not offer', function () {
    $this->get('/login?lang=fr')->assertOk()->assertSee('<html lang="en"', false);
    $this->post('/locale', ['locale' => 'fr'])->assertSessionHasErrors('locale');
});

it('lets a saved choice win over the session', function () {
    $user = lcUser('admin', ['locale' => 'km']);

    $this->actingAs($user)->withSession(['locale' => 'en'])->get('/')->assertSee('<html lang="km"', false);
});

it('shows English for a phrase that has no Khmer yet', function () {
    $this->actingAs(lcUser())->post('/locale', ['locale' => 'km']);

    app()->setLocale('km');

    expect(__('A phrase nobody has translated'))->toBe('A phrase nobody has translated')
        ->and(__('Settings'))->toBe('ការកំណត់');
});

it('uses APP_LOCALE for people who chose nothing', function () {
    config(['locales.default' => 'km']);

    $this->get('/login')->assertOk()->assertSee('<html lang="km"', false);
});

// ------------------------------------------------------------------- the resolver

it('reads the Accept-Language header by quality, then by position', function () {
    $resolver = new LocaleResolver;
    $supported = ['en', 'km'];

    expect($resolver->fromHeader('km-KH,km;q=0.9,en;q=0.8', $supported))->toBe('km')
        ->and($resolver->fromHeader('en;q=0.1,km;q=0.9', $supported))->toBe('km')
        ->and($resolver->fromHeader('en-US,km;q=0.5', $supported))->toBe('en')
        ->and($resolver->fromHeader('fr,de;q=0.5', $supported))->toBeNull()
        ->and($resolver->fromHeader('', $supported))->toBeNull();
});

// --------------------------------------------------------------------- the parent app

it('answers the parent app in the language of its Accept-Language header', function () {
    Sanctum::actingAs(lcUser('parent'));

    $this->getJson('/api/parent/language', ['Accept-Language' => 'km-KH,km;q=0.9,en;q=0.8'])
        ->assertOk()->assertJsonPath('locale', 'km')->assertJsonPath('saved', null);

    $this->getJson('/api/parent/language', ['Accept-Language' => 'fr'])->assertOk()->assertJsonPath('locale', 'en');
    $this->getJson('/api/parent/language')->assertOk()->assertJsonPath('locale', 'en');
});

it('lets the parent save a language that wins over the header', function () {
    $parent = lcUser('parent');
    Sanctum::actingAs($parent);

    $this->putJson('/api/parent/language', ['locale' => 'km'])->assertOk()->assertJsonPath('locale', 'km');
    expect($parent->refresh()->locale)->toBe('km');

    $this->getJson('/api/parent/language', ['Accept-Language' => 'en'])->assertOk()->assertJsonPath('locale', 'km');

    $this->putJson('/api/parent/language', ['locale' => 'fr'])->assertStatus(422);
});

it('does not use a web browser\'s language, only the parent app\'s', function () {
    $this->get('/login', ['Accept-Language' => 'km'])->assertOk()->assertSee('<html lang="en"', false);
});

it('answers the language calls before the consent text is accepted', function () {
    $school = School::create(['name' => 'Consent Lang', 'code' => 'CLANG', 'settings' => ['consent' => ['required' => true]]]);
    app(\App\Services\ConsentService::class)->publish($school, str_repeat('Please accept our terms for the canteen. ', 4));
    Role::findOrCreate('parent', 'web');
    $parent = User::factory()->create(['school_id' => $school->id]);
    $parent->assignRole('parent');
    Sanctum::actingAs($parent);

    $this->getJson('/api/parent/children')->assertForbidden()->assertJsonPath('reason', 'consent_required');
    $this->getJson('/api/parent/language')->assertOk();
    $this->putJson('/api/parent/language', ['locale' => 'km'])->assertOk();
});

// ------------------------------------------------------------------------ the tools

it('lists the phrases that still need a Khmer translation', function () {
    $this->artisan('canteen:i18n:missing')->assertExitCode(0)->expectsOutputToContain('phrase(s) in use');
});

it('reports what the wrapping tool would change without changing anything', function () {
    $before = md5(implode('', array_map('file_get_contents', glob(resource_path('views/*/*.blade.php')) ?: [])));

    Artisan::call('canteen:i18n:wrap');

    $after = md5(implode('', array_map('file_get_contents', glob(resource_path('views/*/*.blade.php')) ?: [])));

    expect($after)->toBe($before)->and(Artisan::output())->toContain('Nothing was changed');
});
