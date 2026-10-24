<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Catch top-ups whose callback never arrived. Needs the scheduler running (php artisan schedule:work in dev).
\Illuminate\Support\Facades\Schedule::command('topups:reconcile')->everyFiveMinutes();

// ---- operations (docs/operations.md): proof that the scheduler and the queue worker run, and the daily backup
\Illuminate\Support\Facades\Schedule::call(fn () => \App\Support\Ops\Heartbeat::beat('scheduler'))->everyMinute()->name('heartbeat-scheduler');
\Illuminate\Support\Facades\Schedule::job(new \App\Jobs\RecordQueueHeartbeat)->everyFiveMinutes();
\Illuminate\Support\Facades\Schedule::command('canteen:backup')->dailyAt('02:00')->withoutOverlapping();
