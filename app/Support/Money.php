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

    /** "1.50" typed by a person -> 150 (USD) or "2500" -> 2500 (KHR). */
    public static function toMinor(string|int|float $value, string $currency = 'USD'): int
    {
        return $currency === 'KHR'
            ? (int) round((float) $value)
            : (int) round((float) $value * 100);
    }

    /** 150 -> "1.50" (USD) or 2500 -> "2500" (KHR), for form fields. */
    public static function toMajor(int $amount, string $currency = 'USD'): string
    {
        return $currency === 'KHR' ? (string) $amount : number_format($amount / 100, 2, '.', '');
    }
}
