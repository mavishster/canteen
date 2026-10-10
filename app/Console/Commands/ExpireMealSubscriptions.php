<?php

namespace App\Console\Commands;

use App\Models\MealSubscription;
use App\Models\School;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('meals:expire-subscriptions')]
#[Description('Mark expired meal subscriptions without deleting their history')]
class ExpireMealSubscriptions extends Command
{
    public function handle(): int
    {
        $expired = 0;

        School::query()->select(['id', 'timezone'])->eachById(function (School $school) use (&$expired): void {
            $expired += MealSubscription::withoutGlobalScopes()
                ->where('school_id', $school->id)
                ->where('status', 'active')
                ->whereDate('expires_on', '<', now($school->timezone)->toDateString())
                ->update(['status' => 'expired']);
        });

        $this->info("Marked {$expired} meal subscription(s) as expired.");

        return self::SUCCESS;
    }
}
