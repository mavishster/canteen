<?php

namespace App\Console\Commands\Concerns;

trait AsksForPassword
{
    /** Asks twice, hidden. Returns null (after saying why) when it cannot be accepted. */
    protected function askForPassword(): ?string
    {
        $password = $this->secret('Password (at least 12 characters)');

        if (! is_string($password) || strlen($password) < 12) {
            $this->error('The password must have at least 12 characters.');

            return null;
        }

        if ($password !== $this->secret('Repeat the password')) {
            $this->error('The two passwords do not match.');

            return null;
        }

        return $password;
    }
}
