<?php

namespace App\Models;

use App\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class SaleRefund extends Model
{
    use BelongsToSchool;

    protected $guarded = [];

    protected $casts = ['decided_at' => 'datetime'];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }
}
