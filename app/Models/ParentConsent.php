<?php

namespace App\Models;

use App\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class ParentConsent extends Model
{
    use BelongsToSchool;

    const UPDATED_AT = null;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A recorded consent cannot be edited.'));
        static::deleting(fn () => throw new LogicException('A recorded consent cannot be deleted.'));
    }

    public function document()
    {
        return $this->belongsTo(ConsentDocument::class, 'consent_document_id');
    }

    public function parentUser()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
