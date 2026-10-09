<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Catch top-ups whose callback never arrived. Needs the scheduler running (php artisan schedule:work in dev).
\Illuminate\Support\Facades\Schedule::command('topups:reconcile')->everyFiveMinutes();
