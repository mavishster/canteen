<?php

use App\Models\School;
use App\Models\User;
use App\Support\I18n\BladeWrapper;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;

function ts3Admin(): User
{
    $school = School::create(['name' => 'Stage3 School', 'code' => 'ST3']);
    Role::findOrCreate('admin', 'web');

    $user = User::factory()->create(['school_id' => $school->id]);
    $user->assignRole('admin');

    return $user;
}

it('shows form errors in Khmer', function () {
    $this->actingAs(ts3Admin())->post('/locale', ['locale' => 'km']);

    $this->post('/admin/products', ['name' => '', 'price' => ''])
        ->assertSessionHasErrors(['name' => 'សូមបំពេញ ឈ្មោះ។', 'price' => 'សូមបំពេញ តម្លៃ។']);
});

it('shows form errors in English by default', function () {
    $this->actingAs(ts3Admin())->post('/admin/products', ['name' => '', 'price' => ''])
        ->assertSessionHasErrors(['name' => 'The name field is required.']);
});

it('has the Khmer system message files', function () {
    foreach (['auth', 'pagination', 'validation'] as $file) {
        expect(is_file(base_path("lang/km/{$file}.php")))->toBeTrue();
        expect(require base_path("lang/km/{$file}.php"))->toBeArray()->not->toBeEmpty();
    }
});

it('lists mixed text, words inside an expression and script strings for a person', function () {
    $source = <<<'BLADE'
<td>Held at {{ $when }} now</td>
<span>{{ $on ? 'Compulsory' : 'Not compulsory' }}</span>
<span>{{ now()->format('j M Y') }}</span>
<h1>Products</h1>
<script>
    alert('Something went wrong, try again');
    $('#x').hide();
</script>
BLADE;

    [$new, , $manual] = (new BladeWrapper)->wrap($source);
    $kinds = collect($manual)->map(fn ($m) => $m[0])->all();
    $texts = collect($manual)->map(fn ($m) => $m[1])->implode(' | ');

    expect($kinds)->toContain('mixed')->toContain('expr')->toContain('js')
        ->and($texts)->toContain('Held at {{ $when }} now')
        ->toContain("{{ \$on ? 'Compulsory' : 'Not compulsory' }}")
        ->toContain('Something went wrong, try again')
        ->and($texts)->not->toContain('j M Y')
        ->and($new)->toContain("{{ __('Products') }}");
});

it('reports what the PHP wrapping tool would change without changing anything', function () {
    $before = md5(implode('', array_map('file_get_contents', glob(app_path('Http/Controllers/*/*.php')) ?: [])));

    Artisan::call('canteen:i18n:wrap-php');

    $after = md5(implode('', array_map('file_get_contents', glob(app_path('Http/Controllers/*/*.php')) ?: [])));

    expect($after)->toBe($before)->and(Artisan::output())->toContain('Nothing was changed');
});

it('lists the places for a person', function () {
    $this->artisan('canteen:i18n:manual')->assertExitCode(0)->expectsOutputToContain('place(s) for a person');
});
