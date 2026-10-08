<?php

namespace App\Support;

use App\Exceptions\CardException;

final class CardUid
{
    /** Canonical form: uppercase hex, no separators or 0x prefix. */
    public static function normalize(string $raw): string
    {
        $uid = strtoupper(trim($raw));
        $uid = preg_replace('/^0X/', '', $uid);
        $uid = preg_replace('/[\s:\-]/', '', $uid);

        // 4, 7 or 10 byte UIDs = 8, 14 or 20 hex characters
        if (
            $uid === '' || !ctype_xdigit($uid) || strlen($uid) % 2 !== 0
            || strlen($uid) < 8 || strlen($uid) > 20
        ) {
            throw CardException::invalidUid();
        }

        return $uid;
    }
}