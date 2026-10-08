<?php

namespace App\Enums;

enum LedgerType: string
{
    case TopUp = 'topup';
    case Purchase = 'purchase';
    case Refund = 'refund';
    case Adjustment = 'adjustment';
}