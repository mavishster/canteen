<?php

namespace App\Models;

use App\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use BelongsToSchool;

    protected $guarded = [];

    protected $casts = [
        'price' => 'integer',
        'is_active' => 'boolean',
        'track_stock' => 'boolean',
        'stock' => 'integer',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
