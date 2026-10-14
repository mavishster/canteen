<?php

use App\Events\LedgerEntryRecorded;
use App\Listeners\SendWalletNotifications;
use App\Models\GuardianLink;
use App\Models\LedgerEntry;
use App\Models\ParentDevice;
use App\Models\ParentMessage;
use App\Models\Product;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\Notifications\FakePush;
use App\Services\Notifications\FakeSms;
use App\Services\SaleService;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    config(['messaging.push' => 'fake', 'messaging.sms' => 'fake']);

    $this->push = new FakePush();
    $this->sms = new FakeSms();
    app()->instance(FakePush::class, $this->push);
    app()->instance(FakeSms::class, $this->sms);
});

function ntfParent(School $school, Student $student): User
{
    Role::findOrCreate('parent', 'web');

    $parent = User::factory()->create(['school_id' => $school->id]);
    $parent->assignRole('parent');
    GuardianLink::create(['school_id' => $school->id, 'user_id' => $parent->id, 'student_id' => $student->id]);

    return $parent->refresh();   // loads the database defaults (notify_* settings)
}

/** @return array{0: School, 1: Student, 2: User} */
function ntfSetup(int $balance = 10000, array $settings = []): array
{
    static $n = 0;
    $n++;

    $school = School::create(['name' => "Notify School {$n}", 'code' => "NTF{$n}", 'settings' => $settings ?: null]);
    $student = Student::create(['school_id' => $school->id, 'student_code' => "N{$n}", 'name' => "Notify{$n} Family", 'is_active' => true]);

    // Money in before the parent exists: this one must not message anyone
    app(LedgerService::class)->topUp($student->account, $balance, "ntf-seed-{$n}");

    return [$school, $student, ntfParent($school, $student)];
}

function ntfSell(School $school, Student $student, int $price, int $qty, string $key): void
{
    $product = Product::create(['school_id' => $school->id, 'name' => "Item {$key}", 'price' => $price]);

    app(SaleService::class)->checkoutForStudent($school->id, null, $student, [
        ['product_id' => $product->id, 'quantity' => $qty],
    ], $key);
}

function ntfMessages(User $parent, ?string $type = null)
{
    return ParentMessage::withoutGlobalScopes()
        ->where('user_id', $parent->id)
        ->when($type, fn($q) => $q->where('type', $type))
        ->orderBy('id')
        ->get();
}

// ------------------------------------------------------------------ top-ups

it('tells the parent when a top-up arrives, by push and SMS', function () {
    [, $student, $parent] = ntfSetup(10000);
    ParentDevice::create(['user_id' => $parent->id, 'platform' => 'android', 'token' => 'device-token-1']);
    $parent->forceFill(['phone' => '+85512345678'])->save();

    app(LedgerService::class)->topUp($student->account, 500, 'ntf-topup-1');

    $message = ntfMessages($parent)->first();

    expect(ntfMessages($parent))->toHaveCount(1)
        ->and($message->type)->toBe('topup')
        ->and($message->body)->toContain('$5.00')->toContain('$105.00')->toContain('Notify1')
        ->and($message->push_status)->toBe('sent')
        ->and($message->sms_status)->toBe('sent')
        ->and($this->push->sent[0]['token'])->toBe('device-token-1')
        ->and($this->push->sent[0]['data']['student_id'])->toBe($student->id)
        ->and($this->sms->sent[0]['phone'])->toBe('+85512345678')
        ->and($this->sms->sent[0]['text'])->not->toContain('Family');   // first name only
});

it('still records the message when there is no phone or device', function () {
    [, $student, $parent] = ntfSetup();

    app(LedgerService::class)->topUp($student->account, 500, 'ntf-topup-1');

    $message = ntfMessages($parent)->first();

    expect($message->push_status)->toBe('no_device')
        ->and($message->sms_status)->toBe('skipped')
        ->and($this->push->sent)->toBe([])
        ->and($this->sms->sent)->toBe([]);
});

it('respects the parent\'s preferences', function () {
    [, $student, $parent] = ntfSetup();
    $parent->forceFill(['notify_topup' => false])->save();

    app(LedgerService::class)->topUp($student->account, 500, 'ntf-topup-1');

    expect(ntfMessages($parent))->toHaveCount(0);
});

it('does not send SMS when the parent has switched SMS off', function () {
    [, $student, $parent] = ntfSetup();
    $parent->forceFill(['phone' => '+85512345678', 'notify_sms' => false])->save();

    app(LedgerService::class)->topUp($student->account, 500, 'ntf-topup-1');

    expect($this->sms->sent)->toBe([])
        ->and(ntfMessages($parent)->first()->sms_status)->toBe('skipped');
});

it('messages every linked parent and nobody else', function () {
    [$school, $student, $mum] = ntfSetup();
    $dad = ntfParent($school, $student);

    $otherStudent = Student::create(['school_id' => $school->id, 'student_code' => 'OTHERKID', 'name' => 'Other Kid']);
    $stranger = ntfParent($school, $otherStudent);

    app(LedgerService::class)->topUp($student->account, 500, 'ntf-topup-1');

    expect(ntfMessages($mum))->toHaveCount(1)
        ->and(ntfMessages($dad))->toHaveCount(1)
        ->and(ntfMessages($stranger))->toHaveCount(0);
});

it('ignores refunds, adjustments and money that nobody is told about', function () {
    [, $student, $parent] = ntfSetup();

    app(LedgerService::class)->adjust($student->account, 100, 'ntf-adjust-1');
    app(LedgerService::class)->refund($student->account, 100, 'ntf-refund-1');

    expect(ntfMessages($parent))->toHaveCount(0);
});

// --------------------------------------------------------------- purchases

it('sends purchase alerts only to parents who asked for them', function () {
    [$school, $student, $parent] = ntfSetup(10000);

    ntfSell($school, $student, 350, 2, 'p1');
    expect(ntfMessages($parent, 'purchase'))->toHaveCount(0);

    $parent->forceFill(['notify_purchase' => true])->save();
    ntfSell($school, $student, 100, 1, 'p2');

    $message = ntfMessages($parent, 'purchase')->first();

    expect($message->body)->toContain('$1.00')->toContain('1× Item p2')->toContain('$92.00');
});

// ------------------------------------------------------------ low balance

it('warns once when the balance drops below the threshold, and again after a new top-up', function () {
    [$school, $student, $parent] = ntfSetup(500);   // default threshold: $2.00 = 200

    ntfSell($school, $student, 250, 1, 's1');   // 500 -> 250: still above the line
    expect(ntfMessages($parent, 'low_balance'))->toHaveCount(0);

    ntfSell($school, $student, 100, 1, 's2');   // 250 -> 150: crossed
    expect(ntfMessages($parent, 'low_balance'))->toHaveCount(1)
        ->and(ntfMessages($parent, 'low_balance')->first()->body)->toContain('$1.50');

    ntfSell($school, $student, 50, 1, 's3');    // 150 -> 100: already below, no second warning
    expect(ntfMessages($parent, 'low_balance'))->toHaveCount(1);

    app(LedgerService::class)->topUp($student->account, 500, 'ntf-topup-2');   // back above the line
    ntfSell($school, $student, 450, 1, 's4');   // 600 -> 150: crossed again

    expect(ntfMessages($parent, 'low_balance'))->toHaveCount(2);
});

it('uses a per-child threshold, and 0 switches the warning off', function () {
    [$school, $student, $parent] = ntfSetup(1500);

    $student->account->forceFill(['low_balance_threshold' => 1000])->save();
    ntfSell($school, $student, 600, 1, 's1');   // 1500 -> 900: below 1000
    expect(ntfMessages($parent, 'low_balance'))->toHaveCount(1);

    app(LedgerService::class)->topUp($student->account, 5000, 'ntf-topup-2');
    $student->account->forceFill(['low_balance_threshold' => 0])->save();
    ntfSell($school, $student, 5500, 1, 's2');   // way below, but warnings are off

    expect(ntfMessages($parent, 'low_balance'))->toHaveCount(1);
});

it('uses the school default threshold when the school sets one', function () {
    [$school, $student, $parent] = ntfSetup(5000, ['notifications' => ['low_balance_default' => 3000]]);

    ntfSell($school, $student, 2500, 1, 's1');   // 5000 -> 2500: below 3000

    expect(ntfMessages($parent, 'low_balance'))->toHaveCount(1);
});

// ------------------------------------------------------------ safety

it('never messages twice for the same ledger entry', function () {
    [, $student, $parent] = ntfSetup();
    app(LedgerService::class)->topUp($student->account, 500, 'ntf-topup-1');

    $entry = LedgerEntry::withoutGlobalScopes()->where('account_id', $student->account->id)->latest('id')->first();

    app(SendWalletNotifications::class)->handle(new LedgerEntryRecorded($entry));
    app(SendWalletNotifications::class)->handle(new LedgerEntryRecorded($entry));

    expect(ntfMessages($parent))->toHaveCount(1);
});

it('keeps the money safe when push or SMS fails', function () {
    [, $student, $parent] = ntfSetup(10000);
    ParentDevice::create(['user_id' => $parent->id, 'platform' => 'ios', 'token' => 'device-token-1']);
    $parent->forceFill(['phone' => '+85512345678'])->save();
    $this->push->failing = true;
    $this->sms->failing = true;

    app(LedgerService::class)->topUp($student->account, 500, 'ntf-topup-1');

    $message = ntfMessages($parent)->first();

    expect($student->account->refresh()->balance)->toBe(10500)
        ->and($message->push_status)->toBe('failed')
        ->and($message->sms_status)->toBe('failed');
});

it('sends nothing when no driver is configured, and says so', function () {
    config(['messaging.push' => 'none', 'messaging.sms' => 'none']);
    [, $student, $parent] = ntfSetup();
    ParentDevice::create(['user_id' => $parent->id, 'platform' => 'ios', 'token' => 'device-token-1']);
    $parent->forceFill(['phone' => '+85512345678'])->save();

    app(LedgerService::class)->topUp($student->account, 500, 'ntf-topup-1');

    $message = ntfMessages($parent)->first();

    expect($message->push_status)->toBe('not_configured')
        ->and($message->sms_status)->toBe('not_configured');
});

// ------------------------------------------------------------ the parent API

it('lists the messages with an unread count and lets the parent mark them read', function () {
    [, $student, $parent] = ntfSetup();
    app(LedgerService::class)->topUp($student->account, 500, 'ntf-a');
    app(LedgerService::class)->topUp($student->account, 300, 'ntf-b');
    Sanctum::actingAs($parent);

    $response = $this->getJson('/api/parent/messages')
        ->assertOk()
        ->assertJsonPath('unread', 2)
        ->assertJsonCount(2, 'data');

    expect($response->json('data.0.body'))->toContain('$3.00')    // newest first
        ->and($response->json('data.0.read'))->toBeFalse();

    $firstId = $response->json('data.1.id');
    $this->postJson('/api/parent/messages/read', ['ids' => [$firstId]])->assertOk();
    $this->getJson('/api/parent/messages')->assertJsonPath('unread', 1);

    $this->postJson('/api/parent/messages/read')->assertOk();
    $this->getJson('/api/parent/messages')->assertJsonPath('unread', 0);
});

it('never shows one parent another parent\'s messages', function () {
    [$school, $student, $mum] = ntfSetup();
    $otherKid = Student::create(['school_id' => $school->id, 'student_code' => 'OTHER2', 'name' => 'Other Two', 'is_active' => true]);
    $neighbour = ntfParent($school, $otherKid);

    app(LedgerService::class)->topUp($student->account, 500, 'ntf-a');

    Sanctum::actingAs($neighbour);
    $this->getJson('/api/parent/messages')->assertOk()->assertJsonPath('unread', 0)->assertJsonCount(0, 'data');

    // ...and cannot mark someone else's message as read
    $messageId = ntfMessages($mum)->first()->id;
    $this->postJson('/api/parent/messages/read', ['ids' => [$messageId]])->assertOk();
    expect(ParentMessage::withoutGlobalScopes()->find($messageId)->read_at)->toBeNull();
});

it('reads and changes the notification settings', function () {
    [, , $parent] = ntfSetup();
    Sanctum::actingAs($parent);

    $this->getJson('/api/parent/notification-settings')
        ->assertOk()
        ->assertJsonPath('topup', true)
        ->assertJsonPath('low_balance', true)
        ->assertJsonPath('purchase', false)
        ->assertJsonPath('sms', true)
        ->assertJsonPath('has_phone', false)
        ->assertJsonPath('children.0.effective_threshold', 200);

    $this->putJson('/api/parent/notification-settings', ['purchase' => true, 'topup' => false])
        ->assertOk()
        ->assertJsonPath('purchase', true)
        ->assertJsonPath('topup', false);

    expect((bool) $parent->refresh()->notify_purchase)->toBeTrue()
        ->and((bool) $parent->notify_topup)->toBeFalse();
});

it('sets and clears the low-balance threshold of a child', function () {
    [, $student, $parent] = ntfSetup();
    Sanctum::actingAs($parent);

    $this->putJson("/api/parent/children/{$student->id}/low-balance", ['threshold' => '10'])
        ->assertOk()
        ->assertJsonPath('low_balance_threshold', 1000)
        ->assertJsonPath('effective_threshold', 1000);

    $this->putJson("/api/parent/children/{$student->id}/low-balance", ['threshold' => null])
        ->assertOk()
        ->assertJsonPath('low_balance_threshold', null)
        ->assertJsonPath('effective_threshold', 200);

    $this->putJson("/api/parent/children/{$student->id}/low-balance", ['threshold' => '0'])
        ->assertOk()
        ->assertJsonPath('effective_threshold', 0);
});

it('does not let a parent change another family\'s threshold', function () {
    [$school, , $parent] = ntfSetup();
    $stranger = Student::create(['school_id' => $school->id, 'student_code' => 'STRANGER', 'name' => 'Stranger', 'is_active' => true]);
    Sanctum::actingAs($parent);

    $this->putJson("/api/parent/children/{$stranger->id}/low-balance", ['threshold' => '10'])->assertNotFound();
});

it('registers, refreshes, moves and removes push devices', function () {
    [$school, $student, $mum] = ntfSetup();
    $dad = ntfParent($school, $student);

    Sanctum::actingAs($mum);
    $this->postJson('/api/parent/devices', ['token' => 'abc-123', 'platform' => 'android'])->assertCreated();
    $this->postJson('/api/parent/devices', ['token' => 'abc-123', 'platform' => 'android'])->assertOk();
    expect(ParentDevice::where('token', 'abc-123')->count())->toBe(1);

    // The same phone is now logged in as someone else: the token follows the new login
    Sanctum::actingAs($dad);
    $this->postJson('/api/parent/devices', ['token' => 'abc-123', 'platform' => 'android'])->assertOk();
    expect((int) ParentDevice::where('token', 'abc-123')->value('user_id'))->toBe($dad->id);

    $this->deleteJson('/api/parent/devices', ['token' => 'abc-123'])->assertOk();
    expect(ParentDevice::count())->toBe(0);
});

it('validates the device registration', function () {
    [, , $parent] = ntfSetup();
    Sanctum::actingAs($parent);

    $this->postJson('/api/parent/devices', ['token' => '', 'platform' => 'windows'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['token', 'platform']);
});

it('keeps non-parents out of the notification endpoints', function () {
    [$school] = ntfSetup();
    Role::findOrCreate('cashier', 'web');
    $cashier = User::factory()->create(['school_id' => $school->id]);
    $cashier->assignRole('cashier');
    Sanctum::actingAs($cashier);

    $this->getJson('/api/parent/messages')->assertForbidden();
    $this->getJson('/api/parent/notification-settings')->assertForbidden();
});
