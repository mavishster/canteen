<?php

namespace App\Models;

use App\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
    use BelongsToSchool;

    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];

    protected static function booted(): void
    {
        // Every student gets a prepaid account automatically
        static::created(function (Student $student) {
            $student->account()->create(['school_id' => $student->school_id]);
        });
    }

    public function account()
    {
        return $this->hasOne(Account::class);
    }

    public function cards()
    {
        return $this->hasMany(Card::class);
    }

    public function activeCard()
    {
        return $this->hasOne(Card::class)->where('status', 'active');
    }
}