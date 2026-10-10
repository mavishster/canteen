<?php

namespace App\Models;

use App\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/** Which parent (a User with the 'parent' role) may see and manage which child. */
class GuardianLink extends Model
{
    use BelongsToSchool;

    protected $guarded = [];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function parentUser()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
