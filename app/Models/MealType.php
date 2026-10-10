<?php

namespace App\Models;

use App\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MealType extends Model
{
    use BelongsToSchool;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'available_days' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function plans(): HasMany
    {
        return $this->hasMany(MealPlan::class);
    }
}
