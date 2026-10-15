<?php

namespace App\Models;

use App\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class MealSubscription extends Model
{
    use BelongsToSchool;

    protected $guarded = [];

    protected $casts = [
        'valid_from' => 'date',
        'expires_on' => 'date',
        'price' => 'integer',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function plan()
    {
        return $this->belongsTo(MealPlan::class, 'meal_plan_id');
    }

    public function mealType()
    {
        return $this->belongsTo(MealType::class);
    }

    public function ledgerEntry()
    {
        return $this->belongsTo(LedgerEntry::class);
    }
}
