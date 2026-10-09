<?php

namespace App\Models;

use App\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    use BelongsToSchool;

    protected $guarded = [];

    protected $casts = ['total' => 'integer'];

    public function items()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function ledgerEntry()
    {
        return $this->belongsTo(LedgerEntry::class);
    }
}
