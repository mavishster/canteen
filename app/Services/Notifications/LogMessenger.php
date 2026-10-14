<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Log;

/** Development only: writes the message to the log instead of sending it. */
class LogMessenger implements PushSender
{
    public function send(string $deviceToken, string $title, string $body, array $data = []): void
    {
        Log::info('[push]', ['token' => substr($deviceToken, 0, 8) . '…', 'title' => $title, 'body' => $body, 'data' => $data]);
    }

    public function sendSms(string $phone, string $text): void
    {
        Log::info('[sms]', ['to' => '…' . substr($phone, -3), 'text' => $text]);
    }
}
