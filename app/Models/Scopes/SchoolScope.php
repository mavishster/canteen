<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class SchoolScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (Auth::hasUser() && Auth::user()->school_id) {
            $builder->where($model->getTable() . '.school_id', Auth::user()->school_id);
        }
    }
}