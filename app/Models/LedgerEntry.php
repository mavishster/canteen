<?php

namespace App\Models;

use App\Concerns\BelongsToSchool;
use App\Enums\LedgerType;
use App\Events\LedgerEntryRecorded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class LedgerEntry extends Model
{
    use BelongsToSchool;

    const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'type' => LedgerType::class,
        'amount' => 'integer',
        'balance_after' => 'integer',
        'exchange_rate' => 'decimal:6',
    ];

    protected static function booted(): void
    {
        // Append-only: history can never be edited or deleted
        static::updating(fn () => throw new LogicException('Ledger entries are immutable.'));
        static::deleting(fn () => throw new LogicException('Ledger entries cannot be deleted.'));

        // Tell the rest of the system (parent notifications). Listeners that queue work wait for the commit.
        static::created(fn (LedgerEntry $entry) => event(new LedgerEntryRecorded($entry)));
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}
