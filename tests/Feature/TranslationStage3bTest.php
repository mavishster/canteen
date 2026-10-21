<?php

use App\Exceptions\SaleException;
use App\Models\Category;
use App\Models\Product;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Services\CardService;
use App\Services\LedgerService;
use App\Services\SaleService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;

function ts4Admin(): User
{
    $school = School::create(['name' => 'Stage3b School', 'code' => 'ST3B']);
    Role::findOrCreate('admin', 'web');

    $user = User::factory()->create(['school_id' => $school->id]);
    $user->assignRole('admin');

    return $user;
}

it('puts the translation helper on the page, with the Khmer phrases only in Khmer', function () {
    $admin = ts4Admin();

    $this->actingAs($admin)->get('/')->assertOk()
        ->assertSee('window.__ = function', false)
        ->assertDontSee('"Settings":"ការកំណត់"', false);

    $this->post('/locale', ['locale' => 'km']);

    $this->get('/')->assertOk()->assertSee('"Settings":"ការកំណត់"', false);
});

it('has Khmer for the messages the server produces', function () {
    app()->setLocale('km');

    foreach ([
        'Category is available again.', 'Consent is no longer compulsory.', 'Meal switched on.', 'Plan switched off.',
        'Product hidden from the till.', 'Stock tracking turned off.', 'Refund requested. A manager has to approve it.',
        'Ban added.', 'Card not recognised.', 'This card is blocked.', 'The cart is empty.', 'Product added.', 'Settings saved.',
        'Something went wrong.', 'Checkout failed', 'Mon', 'Sun',
    ] as $phrase) {
        expect(__($phrase))->not->toBe($phrase, "no Khmer for: {$phrase}");
    }
});

it('fills the values into a Khmer message', function () {
    app()->setLocale('km');

    expect(__('A single purchase cannot be more than :value.', ['value' => '$5.00']))->toContain('$5.00')->not->toContain(':value');
});

it('says in Khmer when a category can be bought, with the day names translated', function () {
    $school = School::create(['name' => 'Closed Hours School', 'code' => 'CHS']);
    $snacks = Category::create(['school_id' => $school->id, 'name' => 'Snacks', 'sell_days' => [1]]);
    $crisps = Product::create(['school_id' => $school->id, 'category_id' => $snacks->id, 'name' => 'Crisps', 'price' => 100]);
    $kid = Student::create(['school_id' => $school->id, 'student_code' => '910001', 'name' => 'Khmer Kid', 'grade' => 3]);
    app(CardService::class)->bind($kid, 'B1B2B3B4');
    app(LedgerService::class)->topUp($kid->account, 5000, 'ts4-seed');

    $this->travelTo(Carbon::parse('2026-10-13 12:00', 'Asia/Phnom_Penh'));   // a Tuesday

    $sell = fn () => app(SaleService::class)->checkout($school->id, null, 'B1B2B3B4', [['product_id' => $crisps->id, 'quantity' => 1]], 'ts4-' . uniqid());

    app()->setLocale('en');
    try {
        $sell();
        $english = null;
    } catch (SaleException $e) {
        $english = $e->getMessage();
    }

    app()->setLocale('km');
    try {
        $sell();
        $khmer = null;
    } catch (SaleException $e) {
        $khmer = $e->getMessage();
    }

    expect($english)->toBe('Snacks can only be bought on Mon.')
        ->and($khmer)->toContain('ច័ន្ទ')->toContain('Snacks')->not->toContain('Mon');
});

it('compiles every view after the conversions', function () {
    $exit = Artisan::call('view:cache');
    $output = Artisan::output();
    Artisan::call('view:clear');

    expect($exit)->toBe(0, $output);
});
