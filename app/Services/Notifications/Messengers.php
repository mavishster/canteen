<?php

namespace App\Services\Notifications;

use InvalidArgumentException;

final class Messengers
{
    /** Null means "send nothing" (driver 'none'). */
    public static function push(): ?PushSender
    {
        return match (config('messaging.push')) {
            'log' => app(LogMessenger::class),
            'fake' => app(FakePush::class),
            'none', null => null,
            default => throw new InvalidArgumentException("Unknown push driver '" . config('messaging.push') . "'."),
        };
    }

    public static function sms(): ?SmsSender
    {
        return match (config('messaging.sms')) {
            'log' => app(LogSmsSender::class),
            'fake' => app(FakeSms::class),
            'none', null => null,
            default => throw new InvalidArgumentException("Unknown SMS driver '" . config('messaging.sms') . "'."),
        };
    }
}
