<?php

namespace App\Support;

final class Money
{
    /** Amounts are stored in minor units: cents for USD, riel for KHR. */
    public static function format(int $amount, string $currency = 'USD'): string
    {
        if ($currency === 'KHR') {
            return number_format($amount) . ' ៛';
        }

        return '$' . number_format($amount / 100, 2);
    }
}
