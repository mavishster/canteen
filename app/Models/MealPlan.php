<?php

namespace App\Models;

use App\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class MealPlan extends Model
{
    use BelongsToSchool;

    protected $guarded = [];

    protected $casts = [
        'price' => 'integer',
        'min_grade' => 'integer',
        'max_grade' => 'integer',
        'duration_months' => 'integer',
        'is_active' => 'boolean',
    ];

    public function mealType()
    {
        return $this->belongsTo(MealType::class);
    }

    /** A plan with no grade range is for the whole school. A student with no grade only fits those. */
    public function fitsGrade(?int $grade): bool
    {
        if ($this->min_grade === null && $this->max_grade === null) {
            return true;
        }

        if ($grade === null) {
            return false;
        }

        return ($this->min_grade === null || $grade >= $this->min_grade)
            && ($this->max_grade === null || $grade <= $this->max_grade);
    }
}
