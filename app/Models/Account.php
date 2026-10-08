<?php

namespace App\Models;

use App\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use BelongsToSchool;

    protected $guarded = [];
    protected $casts = ['balance' => 'integer'];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}