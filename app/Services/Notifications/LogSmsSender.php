<?php

namespace App\Services\Notifications;

class LogSmsSender implements SmsSender
{
    public function __construct(private LogMessenger $log)
    {
    }

    public function send(string $phone, string $text): void
    {
        $this->log->sendSms($phone, $text);
    }
}
