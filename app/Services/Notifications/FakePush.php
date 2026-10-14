<?php

namespace App\Services\Notifications;

use RuntimeException;

/** For tests: remembers every push, and can be told to fail. */
class FakePush implements PushSender
{
    public bool $failing = false;

    /** @var array<int, array{token: string, title: string, body: string, data: array<string, mixed>}> */
    public array $sent = [];

    public function send(string $deviceToken, string $title, string $body, array $data = []): void
    {
        if ($this->failing) {
            throw new RuntimeException('The fake push service is switched to fail.');
        }

        $this->sent[] = ['token' => $deviceToken, 'title' => $title, 'body' => $body, 'data' => $data];
    }
}
