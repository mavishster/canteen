<?php

namespace App\Providers;

use App\Support\Audit\AuditRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AuditServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Every create / update / delete of an audited model passes here, wherever in the code it happens
        foreach (['created', 'updated', 'deleted'] as $event) {
            Event::listen("eloquent.{$event}: *", function (string $name, array $payload) use ($event) {
                $model = $payload[0] ?? null;

                if (! $model instanceof Model || ! isset(AuditRecorder::registry()[$model::class])) {
                    return;
                }

                try {
                    app(AuditRecorder::class)->modelEvent($event, $model);
                } catch (Throwable $e) {
                    report($e);   // the audit must never break the action it records
                }
            });
        }
    }
}
