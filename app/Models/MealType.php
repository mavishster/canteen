<?php

namespace App\Models;

use App\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class MealType extends Model
{
    use BelongsToSchool;

    protected $guarded = [];

    protected $casts = [
        'available_days' => 'array',
        'is_active' => 'boolean',
    ];

    public function plans()
    {
        return $this->hasMany(MealPlan::class);
    }
}
