<?php

namespace App\Models;

use App\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class StockMovement extends Model
{
    use BelongsToSchool;

    const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'quantity' => 'integer',
        'stock_after' => 'integer',
    ];

    protected static function booted(): void
    {
        // Append-only, like the money ledger
        static::updating(fn () => throw new LogicException('Stock movements are immutable.'));
        static::deleting(fn () => throw new LogicException('Stock movements cannot be deleted.'));
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
