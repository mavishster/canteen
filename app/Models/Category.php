<?php

namespace App\Models;

use App\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use BelongsToSchool;

    protected $guarded = [];

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
