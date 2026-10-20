<?php

use App\Models\AuditLog;
use App\Models\ConsentDocument;
use App\Models\ParentConsent;
use App\Models\School;
use App\Models\User;
use App\Services\ConsentService;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

function cnsSchool(array $settings = []): School
{
    static $n = 0;
    $n++;

    return School::create(['name' => "Consent School {$n}", 'code' => "CNS{$n}", 'settings' => $settings ?: null]);
}

function cnsUser(School $school, string $role): User
{
    Role::findOrCreate($role, 'web');

    $user = User::factory()->create(['school_id' => $school->id]);
    $user->assignRole($role);

    return $user->refresh();
}

function cnsText(string $suffix = ''): string
{
    return str_repeat("We keep your child's canteen information safe and use it only for the canteen. ", 3) . $suffix;
}

/** A school that makes consent compulsory, with a published text, and a parent. */
function cnsStrict(): array
{
    $school = cnsSchool(['consent' => ['required' => true]]);
    app(ConsentService::class)->publish($school, cnsText());

    return [$school, cnsUser($school, 'parent')];
}

// ---------------------------------------------------------------- the app gate

it('does not block parents while consent is not required', function () {
    $school = cnsSchool();
    app(ConsentService::class)->publish($school, cnsText());   // a text exists, but is not compulsory
    $parent = cnsUser($school, 'parent');
    Sanctum::actingAs($parent);

    $this->getJson('/api/parent/children')->assertOk();
    $this->getJson('/api/parent/consent')->assertOk()->assertJsonPath('required', false)->assertJsonPath('must_accept', false);
});

it('locks the app until the newest text is accepted', function () {
    [, $parent] = cnsStrict();
    Sanctum::actingAs($parent);

    $this->getJson('/api/parent/children')->assertForbidden()->assertJsonPath('reason', 'consent_required');
    $this->getJson('/api/parent/messages')->assertForbidden()->assertJsonPath('reason', 'consent_required');

    $this->getJson('/api/parent/consent')
        ->assertOk()
        ->assertJsonPath('required', true)
        ->assertJsonPath('must_accept', true)
        ->assertJsonPath('version', 1)
        ->assertJsonPath('accepted_at', null);

    $this->postJson('/api/parent/consent', ['version' => 1])->assertOk()->assertJsonPath('ok', true);

    $this->getJson('/api/parent/children')->assertOk();
    $this->getJson('/api/parent/consent')->assertOk()->assertJsonPath('must_accept', false);
});

it('records an acceptance once, with evidence, and writes it to the audit log', function () {
    [, $parent] = cnsStrict();
    Sanctum::actingAs($parent);

    $this->postJson('/api/parent/consent', ['version' => 1])->assertOk();
    $this->postJson('/api/parent/consent', ['version' => 1])->assertOk();   // a second tap changes nothing

    $record = ParentConsent::withoutGlobalScopes()->first();

    expect(ParentConsent::withoutGlobalScopes()->count())->toBe(1)
        ->and($record->user_id)->toBe($parent->id)
        ->and($record->ip)->toBe('127.0.0.1')
        ->and(AuditLog::withoutGlobalScopes()->where('action', 'consent.accepted')->count())->toBe(1)
        ->and(AuditLog::withoutGlobalScopes()->where('action', 'consent.accepted')->first()->actor_name)->toBe($parent->name);
});

it('refuses an acceptance of the wrong or a missing version', function () {
    [, $parent] = cnsStrict();
    Sanctum::actingAs($parent);

    $this->postJson('/api/parent/consent', ['version' => 99])->assertStatus(409)->assertJsonPath('reason', 'version_changed');
    $this->postJson('/api/parent/consent', [])->assertStatus(422);

    expect(ParentConsent::withoutGlobalScopes()->count())->toBe(0);
});

it('asks everyone again when a new version is published', function () {
    [$school, $parent] = cnsStrict();
    Sanctum::actingAs($parent);
    $this->postJson('/api/parent/consent', ['version' => 1])->assertOk();
    $this->getJson('/api/parent/children')->assertOk();

    app(ConsentService::class)->publish($school, cnsText('Changed.'));

    $this->getJson('/api/parent/children')->assertForbidden()->assertJsonPath('reason', 'consent_required');
    $this->getJson('/api/parent/consent')->assertJsonPath('version', 2)->assertJsonPath('accepted_at', null);

    $this->postJson('/api/parent/consent', ['version' => 1])->assertStatus(409);   // the old one no longer counts
    $this->postJson('/api/parent/consent', ['version' => 2])->assertOk();
    $this->getJson('/api/parent/children')->assertOk();

    expect(ParentConsent::withoutGlobalScopes()->count())->toBe(2);   // both acceptances stay on record
});

it('does not lock anyone out when consent is on but no text is published yet', function () {
    $school = cnsSchool(['consent' => ['required' => true]]);
    Sanctum::actingAs(cnsUser($school, 'parent'));

    $this->getJson('/api/parent/children')->assertOk();
    $this->getJson('/api/parent/consent')->assertOk()->assertJsonPath('required', false)->assertJsonPath('must_accept', false);
    $this->postJson('/api/parent/consent', ['version' => 1])->assertStatus(409)->assertJsonPath('reason', 'no_document');
});

it('only gates the parents of a school that requires consent', function () {
    cnsStrict();
    $relaxed = cnsSchool();
    Sanctum::actingAs(cnsUser($relaxed, 'parent'));

    $this->getJson('/api/parent/children')->assertOk();
});

it('keeps published texts and recorded acceptances unchangeable', function () {
    [$school, $parent] = cnsStrict();
    Sanctum::actingAs($parent);
    $this->postJson('/api/parent/consent', ['version' => 1])->assertOk();

    $document = ConsentDocument::withoutGlobalScopes()->first();
    $consent = ParentConsent::withoutGlobalScopes()->first();

    expect(fn () => $document->update(['text' => 'changed']))->toThrow(LogicException::class);
    expect(fn () => $document->delete())->toThrow(LogicException::class);
    expect(fn () => $consent->update(['ip' => '1.2.3.4']))->toThrow(LogicException::class);
    expect(fn () => $consent->delete())->toThrow(LogicException::class);
});

// ------------------------------------------------------------------- admin page

it('lets an admin publish versions, and shows the text', function () {
    $school = cnsSchool();
    $this->actingAs(cnsUser($school, 'admin'));

    $this->post('/admin/consent/publish', ['text' => cnsText('First.')])->assertRedirect(route('admin.consent'));
    $this->post('/admin/consent/publish', ['text' => cnsText('Second.')])->assertRedirect(route('admin.consent'));

    expect(ConsentDocument::withoutGlobalScopes()->where('school_id', $school->id)->pluck('version')->all())->toBe([1, 2]);

    $this->get('/admin/consent')->assertOk()->assertSee('Version 2')->assertSee('Second.');
});

it('refuses a text that is too short and shows the standard text to start from', function () {
    $school = cnsSchool();
    $this->actingAs(cnsUser($school, 'admin'));

    $this->post('/admin/consent/publish', ['text' => 'too short'])->assertSessionHasErrors('text');
    expect(ConsentDocument::withoutGlobalScopes()->count())->toBe(0);

    $this->flushSession();   // after a failed submit the page keeps what was typed; start clean
    $this->get('/admin/consent')->assertOk()->assertSee('cashless canteen: how we use your child');
});

it('switches consent on only after a text exists, and records the change', function () {
    $school = cnsSchool();
    $this->actingAs(cnsUser($school, 'admin'));

    $this->post('/admin/consent/toggle')->assertSessionHasErrors('consent');
    expect(app(ConsentService::class)->required($school->refresh()))->toBeFalse();

    $this->post('/admin/consent/publish', ['text' => cnsText()])->assertRedirect();
    $this->post('/admin/consent/toggle')->assertRedirect();

    expect(app(ConsentService::class)->required($school->refresh()))->toBeTrue()
        ->and(AuditLog::withoutGlobalScopes()->where('action', 'school.settings_changed')->latest('id')->first()->summary)->toContain('consent.required')
        ->and(AuditLog::withoutGlobalScopes()->where('action', 'consent.published')->count())->toBe(1);

    $this->post('/admin/consent/toggle')->assertRedirect();
    expect(app(ConsentService::class)->required($school->refresh()))->toBeFalse();
});

it('shows how many parents have accepted and who has not', function () {
    [$school, $accepted] = cnsStrict();
    $slow = cnsUser($school, 'parent');
    $slow->update(['name' => 'Slow Parent']);
    cnsUser($school, 'parent');
    app(ConsentService::class)->accept($accepted, 1, '127.0.0.1');

    $this->actingAs(cnsUser($school, 'admin'))
        ->get('/admin/consent')
        ->assertOk()
        ->assertSee('1 of 3 parents have accepted')
        ->assertSee('Slow Parent')
        ->assertDontSee($accepted->name);
});

it('keeps another school\'s consent and parents out of the page', function () {
    [$other] = cnsStrict();
    $mine = cnsSchool();
    $this->actingAs(cnsUser($mine, 'admin'));

    $this->get('/admin/consent')->assertOk()->assertSee('None published')->assertSee('0 of 0 parents have accepted');
});

it('keeps managers, cashiers and parents out of the consent page', function () {
    $school = cnsSchool();

    foreach (['manager', 'cashier', 'parent'] as $role) {
        $this->actingAs(cnsUser($school, $role))->get('/admin/consent')->assertForbidden();
        $this->actingAs(cnsUser($school, $role))->post('/admin/consent/publish', ['text' => cnsText()])->assertForbidden();
    }
});

// ------------------------------------------------------------ readiness check

it('tells the readiness check when schools do not ask for consent', function () {
    $school = cnsSchool();

    Artisan::call('canteen:check', ['--json' => true]);
    $line = collect(json_decode(Artisan::output(), true))->firstWhere('area', 'Parental consent');

    expect($line['level'])->toBe('WARN')->and($line['message'])->toContain('1 school(s) do not ask');

    app(ConsentService::class)->publish($school, cnsText());
    $school->update(['settings' => ['consent' => ['required' => true]]]);

    Artisan::call('canteen:check', ['--json' => true]);
    $line = collect(json_decode(Artisan::output(), true))->firstWhere('area', 'Parental consent');

    expect($line['level'])->toBe('OK');
});

it('warns when consent is required but no text is published', function () {
    cnsSchool(['consent' => ['required' => true]]);

    Artisan::call('canteen:check', ['--json' => true]);
    $line = collect(json_decode(Artisan::output(), true))->firstWhere('area', 'Parental consent');

    expect($line['level'])->toBe('WARN')->and($line['message'])->toContain('have not published a text');
});
