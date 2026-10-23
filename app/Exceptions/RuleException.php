<?php

namespace App\Exceptions;

use App\Support\Money;

class RuleException extends SaleException
{
    /** @param array<int, array{0:string,1:string}> $windows */
    public static function outsideHours(array $windows): self
    {
        $message = $windows
            ? __('Purchases are not allowed at this time. Buying hours: :hours.', [
                'hours' => implode(', ', array_map(fn ($w) => "{$w[0]}–{$w[1]}", $windows)),
            ])
            : __('Purchases are not allowed at this time.');

        return new self('outside_hours', $message);
    }

    /** @param array<int, string> $names */
    public static function bannedProducts(array $names): self
    {
        return new self('banned_product', __(':value is not allowed for this student.', ['value' => implode(', ', $names)]));
    }

    public static function overLimit(string $kind, int $remaining, string $currency): self
    {
        $value = Money::format($remaining, $currency);

        return new self(
            "over_{$kind}_limit",
            $kind === 'daily'
                ? __('Daily spending limit reached. Remaining today: :value.', ['value' => $value])
                : __('Weekly spending limit reached. Remaining this week: :value.', ['value' => $value])
        );
    }

    public static function limitAboveSchoolMax(string $kind, int $max, string $currency): self
    {
        return new self(
            'limit_above_school_max',
            __('The :kind limit cannot be above the school maximum of :value.', ['kind' => __($kind), 'value' => Money::format($max, $currency)])
        );
    }
}
