<?php

namespace App\Services\Notifications;

interface SmsSender
{
    /** Sends one text message. Throws if it could not be sent. */
    public function send(string $phone, string $text): void;
}
