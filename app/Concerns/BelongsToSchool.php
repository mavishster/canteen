<?php

namespace App\Concerns;

use App\Models\School;
use App\Models\Scopes\SchoolScope;
use Illuminate\Support\Facades\Auth;

trait BelongsToSchool
{
    protected static function bootBelongsToSchool(): void
    {
        static::addGlobalScope(new SchoolScope);

        static::creating(function ($model) {
            if (!$model->school_id && Auth::hasUser() && Auth::user()->school_id) {
                $model->school_id = Auth::user()->school_id;
            }
        });
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }
}