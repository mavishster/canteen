<?php

namespace App\Services\Notifications;

use App\Models\School;

final class LowBalance
{
    /**
     * The balance below which a parent is warned: the parent's own figure for this child, otherwise the school's default
     * (settings.notifications.low_balance_default), otherwise $2 (8,000 riel for a riel school). 0 switches the alert off.
     */
    public static function thresholdFor(?int $personal, School $school): int
    {
        if ($personal !== null) {
            return max(0, $personal);
        }

        $default = $school->currency === 'KHR' ? 8000 : 200;

        return max(0, (int) data_get($school->settings, 'notifications.low_balance_default', $default));
    }
}
