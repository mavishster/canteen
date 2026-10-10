<?php

namespace App\Support;

use App\Exceptions\CardException;
use InvalidArgumentException;

/** Turns the way the SIS writes a card number into the way the canteen reader types it. */
final class SisCardFormat
{
    public const MODES = ['same', 'decimal_to_hex', 'decimal_to_hex_reversed'];

    public static function convert(string $raw, string $mode = 'same'): string
    {
        $raw = trim($raw);

        return match ($mode) {
            'same' => $raw,
            'decimal_to_hex' => self::decimalToHex($raw),
            'decimal_to_hex_reversed' => self::reverseBytes(self::decimalToHex($raw)),
            default => throw new InvalidArgumentException("Unknown SIS card format '{$mode}'."),
        };
    }

    private static function decimalToHex(string $raw): string
    {
        if ($raw === '' || ! ctype_digit($raw) || strlen($raw) > 15) {
            throw CardException::invalidUid();
        }

        $hex = strtoupper(dechex((int) $raw));

        // At least 4 bytes, always whole bytes
        return str_pad($hex, max(8, strlen($hex) + strlen($hex) % 2), '0', STR_PAD_LEFT);
    }

    private static function reverseBytes(string $hex): string
    {
        return implode('', array_reverse(str_split($hex, 2)));
    }
}
