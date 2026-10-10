<?php

namespace App\Models;

use App\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class MealConsumption extends Model
{
    use BelongsToSchool;

    const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['service_date' => 'date'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Meal consumption records are immutable.'));
        static::deleting(fn () => throw new LogicException('Meal consumption records cannot be deleted.'));
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function mealType(): BelongsTo
    {
        return $this->belongsTo(MealType::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(MealSubscription::class, 'meal_subscription_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}
