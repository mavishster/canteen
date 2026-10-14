<?php

namespace App\Events;

use App\Models\LedgerEntry;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LedgerEntryRecorded
{
    use Dispatchable, SerializesModels;

    public function __construct(public LedgerEntry $entry)
    {
    }
}
