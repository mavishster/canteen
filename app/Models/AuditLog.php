<?php

namespace App\Models;

use App\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class AuditLog extends Model
{
    use BelongsToSchool;

    const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
    ];

    protected static function booted(): void
    {
        // The history of who did what can never be edited or erased
        static::updating(fn () => throw new LogicException('Audit log entries are immutable.'));
        static::deleting(fn () => throw new LogicException('Audit log entries cannot be deleted.'));
    }
}
