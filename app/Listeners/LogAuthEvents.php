<?php

namespace App\Listeners;

use App\Support\Audit\AuditRecorder;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;

/** Staff sign-ins and failed attempts. Never records a password. */
class LogAuthEvents
{
    public function handleLogin(Login $event): void
    {
        if ($event->guard !== 'web') {
            return;
        }

        $user = $event->user;

        AuditRecorder::log(
            'auth.login',
            'auth',
            (string) $user->name,
            "{$user->name} signed in",
            schoolId: $user->school_id,
            actor: $user,
        );
    }

    public function handleFailed(Failed $event): void
    {
        if ($event->guard !== 'web') {
            return;
        }

        $email = mb_substr((string) ($event->credentials['email'] ?? '?'), 0, 120);

        AuditRecorder::log(
            'auth.failed',
            'auth',
            $email,
            "Failed sign-in for {$email}",
            schoolId: $event->user?->school_id,
            actorName: $email,
        );
    }
}
