<?php

namespace App\Models;

use App\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class ConsentDocument extends Model
{
    use BelongsToSchool;

    const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = ['version' => 'integer'];

    protected static function booted(): void
    {
        // A text that parents have accepted can never be changed or removed: publish a new version instead
        static::updating(fn () => throw new LogicException('A published consent text cannot be edited. Publish a new version.'));
        static::deleting(fn () => throw new LogicException('A published consent text cannot be deleted.'));
    }
}
