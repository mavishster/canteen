<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class School extends Model
{
    protected $guarded = [];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * School-wide maximum spending for a grade, in minor units. $kind is 'daily' or 'weekly'.
     * settings.limits.weekly = [[1, 3, 3000], [4, 6, 4000]]  means grades 1-3 => 3000, grades 4-6 => 4000.
     * Null = no school cap.
     */
    public function capForGrade(string $kind, ?int $grade): ?int
    {
        if ($grade === null) {
            return null;
        }

        foreach (data_get($this->settings, "limits.{$kind}", []) as [$min, $max, $cap]) {
            if ($grade >= $min && $grade <= $max) {
                return (int) $cap;
            }
        }

        return null;
    }

    /**
     * settings.buying_hours = {"days": [1,2,3,4,5], "windows": [["07:00","09:00"], ["11:30","13:30"]]}
     * Days are ISO (1 = Monday ... 7 = Sunday), times are in the school's timezone. Null = no restriction.
     */
    public function buyingHours(): ?array
    {
        $hours = data_get($this->settings, 'buying_hours');

        return is_array($hours) ? $hours : null;
    }
}
