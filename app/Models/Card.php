<?php

namespace App\Models;

use App\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class Card extends Model
{
    use BelongsToSchool;

    const ACTIVE = 'active';
    const BLOCKED = 'blocked';
    const RETIRED = 'retired';

    protected $guarded = [];

    protected $casts = [
        'activated_at' => 'datetime',
        'retired_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}