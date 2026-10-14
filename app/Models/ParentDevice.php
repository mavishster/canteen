<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParentDevice extends Model
{
    protected $guarded = [];

    protected $casts = ['last_seen_at' => 'datetime'];
}
