<?php

namespace App\Models;

use App\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MealSubscription extends Model
{
    use BelongsToSchool;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'expires_on' => 'date',
            'entitlement_quantity' => 'integer',
            'consumed_quantity' => 'integer',
            'price' => 'integer',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MealPlan::class, 'meal_plan_id');
    }

    public function mealType(): BelongsTo
    {
        return $this->belongsTo(MealType::class);
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class);
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(MealConsumption::class);
    }

    public function remainingEntitlements(?string $asOfDate = null): int
    {
        $today = $asOfDate ?? now($this->school->timezone)->toDateString();

        if (
            $this->status !== 'active'
            || $this->valid_from->toDateString() > $today
            || $this->expires_on->toDateString() < $today
        ) {
            return 0;
        }

        return max(0, $this->entitlement_quantity - $this->consumed_quantity);
    }
}
