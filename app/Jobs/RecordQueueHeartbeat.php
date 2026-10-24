<?php

namespace App\Jobs;

use App\Support\Ops\Heartbeat;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Sent through the queue every few minutes: when it is processed, a worker is alive. */
class RecordQueueHeartbeat implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        Heartbeat::beat('queue');
    }
}
