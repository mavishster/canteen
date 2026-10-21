<?php

namespace App\Exceptions;

use App\Support\Money;

class RuleException extends SaleException
{
    /** @param array<int, array{0:string,1:string}> $windows */
    public static function outsideHours(array $windows): self
    {
        $text = $windows
            ? ' Buying hours: ' . implode(', ', array_map(fn ($w) => "{$w[0]}–{$w[1]}", $windows)) . '.'
            : '';

        return new self('outside_hours', __('Purchases are not allowed at this time.:text', ['text' => $text]));
    }

    /** @param array<int, string> $names */
    public static function bannedProducts(array $names): self
    {
        return new self('banned_product', __(':value is not allowed for this student.', ['value' => implode(', ', $names)]));
    }

    public static function overLimit(string $kind, int $remaining, string $currency): self
    {
        $label = $kind === 'daily' ? 'Daily' : 'Weekly';
        $period = $kind === 'daily' ? 'today' : 'this week';

        return new self(
            "over_{$kind}_limit",
            __(':label spending limit reached. Remaining :period: :value.', ['label' => $label, 'period' => $period, 'value' => Money::format($remaining, $currency)])
        );
    }

    public static function limitAboveSchoolMax(string $kind, int $max, string $currency): self
    {
        return new self(
            'limit_above_school_max',
            __('The :kind limit cannot be above the school maximum of :value.', ['kind' => $kind, 'value' => Money::format($max, $currency)])
        );
    }
}
