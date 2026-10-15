<?php

namespace App\Listeners;

use App\Events\LedgerEntryRecorded;
use App\Models\Account;
use App\Models\GuardianLink;
use App\Models\LedgerEntry;
use App\Models\ParentDevice;
use App\Models\ParentMessage;
use App\Models\Sale;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Services\Notifications\LowBalance;
use App\Services\Notifications\Messengers;
use App\Support\Money;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns money movements into messages for the child's parents. It runs on the queue and only after the
 * database commit, and it never lets a notification problem touch the money.
 */
class SendWalletNotifications implements ShouldQueue
{
    public bool $afterCommit = true;

    public function handle(LedgerEntryRecorded $event): void
    {
        try {
            $this->notify($event->entry);
        } catch (Throwable $e) {
            report($e);   // a failed notification must never affect a top-up or a sale
        }
    }

    private function notify(LedgerEntry $entry): void
    {
        $type = $entry->type->value;

        if (! in_array($type, ['topup', 'purchase'], true)) {
            return;
        }

        $account = Account::withoutGlobalScopes()->find($entry->account_id);
        $student = $account ? Student::withoutGlobalScopes()->find($account->student_id) : null;
        $school = School::find($entry->school_id);

        if (! $account || ! $student || ! $school) {
            return;
        }

        $parentIds = GuardianLink::withoutGlobalScopes()->where('student_id', $student->id)->pluck('user_id');
        $parents = User::whereIn('id', $parentIds)->get();

        if ($parents->isEmpty()) {
            return;
        }

        $child = Str::before($student->name, ' ');   // first name only: texts are not private
        $fmt = fn (int $minor) => Money::format($minor, $school->currency);
        $balance = $fmt($entry->balance_after);
        $data = ['student_id' => $student->id];

        foreach ($parents as $parent) {
            if ($type === 'topup' && $parent->notify_topup) {
                $this->send($parent, $student, $entry, 'topup', 'Top-up received',
                    "{$child}: {$fmt($entry->amount)} added. Balance: {$balance}.", $data);
            }

            if ($type === 'purchase') {
                if ($parent->notify_purchase) {
                    $this->send($parent, $student, $entry, 'purchase', 'Purchase',
                        "{$child} spent {$fmt(abs($entry->amount))}: {$this->items($entry)}. Balance: {$balance}.", $data);
                }

                // Only the moment the balance crosses the line, not every purchase below it
                $threshold = LowBalance::thresholdFor($account->low_balance_threshold, $school);
                $before = $entry->balance_after - $entry->amount;   // amount is negative for a purchase

                if ($parent->notify_low_balance && $threshold > 0 && $before >= $threshold && $entry->balance_after < $threshold) {
                    $this->send($parent, $student, $entry, 'low_balance', 'Low balance',
                        "{$child}'s balance is {$balance}. Time to top up.", $data);
                }
            }
        }
    }

    private function items(LedgerEntry $entry): string
    {
        $sale = Sale::withoutGlobalScopes()->with('items')->where('ledger_entry_id', $entry->id)->first();

        return $sale
            ? $sale->items->map(fn ($i) => "{$i->quantity}× {$i->name}")->implode(', ')
            : ($entry->description ?: 'canteen purchase');
    }

    /** @param array<string, mixed> $data */
    private function send(User $parent, Student $student, LedgerEntry $entry, string $type, string $title, string $body, array $data): void
    {
        try {
            $message = ParentMessage::withoutGlobalScopes()->firstOrCreate(
                ['user_id' => $parent->id, 'ledger_entry_id' => $entry->id, 'type' => $type],
                [
                    'school_id' => $entry->school_id,
                    'student_id' => $student->id,
                    'title' => $title,
                    'body' => $body,
                    'data' => $data + ['type' => $type],
                ]
            );
        } catch (UniqueConstraintViolationException) {
            return;   // another worker just created it
        }

        if (! $message->wasRecentlyCreated) {
            return;   // already handled: a repeated job must not message twice
        }

        $this->deliver($message, $parent);
    }

    private function deliver(ParentMessage $message, User $parent): void
    {
        // ---- push to the parent's phones
        $push = Messengers::push();
        $devices = ParentDevice::where('user_id', $parent->id)->get();

        if (! $push) {
            $pushStatus = 'not_configured';
        } elseif ($devices->isEmpty()) {
            $pushStatus = 'no_device';
        } else {
            $pushStatus = 'sent';

            foreach ($devices as $device) {
                try {
                    $push->send($device->token, $message->title, $message->body, $message->data ?? []);
                } catch (Throwable $e) {
                    report($e);
                    $pushStatus = 'failed';
                }
            }
        }

        // ---- SMS, only when the parent allows it and the school has their number
        $sms = Messengers::sms();

        if (! $parent->notify_sms || ! $parent->phone) {
            $smsStatus = 'skipped';
        } elseif (! $sms) {
            $smsStatus = 'not_configured';
        } else {
            try {
                $sms->send($parent->phone, $message->body);
                $smsStatus = 'sent';
            } catch (Throwable $e) {
                report($e);
                $smsStatus = 'failed';
            }
        }

        $message->update(['push_status' => $pushStatus, 'sms_status' => $smsStatus]);
    }
}
