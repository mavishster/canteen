<?php

namespace App\Services\Notifications;

use RuntimeException;

/** For tests: remembers every text message, and can be told to fail. */
class FakeSms implements SmsSender
{
    public bool $failing = false;

    /** @var array<int, array{phone: string, text: string}> */
    public array $sent = [];

    public function send(string $phone, string $text): void
    {
        if ($this->failing) {
            throw new RuntimeException('The fake SMS service is switched to fail.');
        }

        $this->sent[] = ['phone' => $phone, 'text' => $text];
    }
}
