<?php

namespace App\Models;

use App\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class MealConsumption extends Model
{
    use BelongsToSchool;

    const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = ['service_date' => 'date'];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function mealType()
    {
        return $this->belongsTo(MealType::class);
    }

    public function subscription()
    {
        return $this->belongsTo(MealSubscription::class, 'meal_subscription_id');
    }
}
