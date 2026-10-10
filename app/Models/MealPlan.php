<?php

namespace App\Models;

use App\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MealPlan extends Model
{
    use BelongsToSchool;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'grade' => 'integer',
            'duration_months' => 'integer',
            'entitlement_quantity' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function mealType(): BelongsTo
    {
        return $this->belongsTo(MealType::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(MealSubscription::class);
    }
}
