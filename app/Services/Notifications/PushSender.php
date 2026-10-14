<?php

namespace App\Services\Notifications;

interface PushSender
{
    /**
     * Sends one push notification to one phone. Throws if it could not be sent.
     *
     * @param  array<string, mixed>  $data  small payload so the app can open the right screen
     */
    public function send(string $deviceToken, string $title, string $body, array $data = []): void;
}
