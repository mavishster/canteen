<?php

namespace App\Support;

/** Turns the many ways people write a phone number into one form for SMS (+855...), or null if it cannot be trusted. */
final class PhoneNumber
{
    public static function normalize(?string $raw, string $defaultCountry = '855'): ?string
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        $hasPlus = str_starts_with($raw, '+');
        $digits = preg_replace('/\D+/', '', $raw);

        if ($digits === '') {
            return null;
        }

        if (! $hasPlus) {
            if (str_starts_with($digits, '00')) {
                $digits = substr($digits, 2);                              // 00855... means +855...
            } elseif (str_starts_with($digits, '0')) {
                $digits = $defaultCountry . substr($digits, 1);            // local form: 012 345 678
            } elseif (! str_starts_with($digits, $defaultCountry)) {
                return null;                                               // no country, no leading 0: do not guess
            }
        }

        $length = strlen($digits);

        if (str_starts_with($digits, '855')) {
            $national = $length - 3;   // Cambodian numbers have 8 or 9 digits after the country code

            if ($national < 8 || $national > 9) {
                return null;
            }
        } elseif ($length < 8 || $length > 15) {
            return null;
        }

        return '+' . $digits;
    }
}
