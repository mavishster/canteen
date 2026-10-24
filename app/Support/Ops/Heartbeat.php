<?php

namespace App\Support\Ops;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** "This still happens": the scheduler, the queue worker, the backup each leave a time here. */
final class Heartbeat
{
    public static function beat(string $name, ?string $note = null): void
    {
        DB::table('heartbeats')->upsert(
            [['name' => $name, 'beat_at' => now()->utc()->toDateTimeString(), 'note' => $note]],
            ['name'],
            ['beat_at', 'note']
        );
    }

    /** @return array{at: Carbon, note: ?string, seconds: int}|null */
    public static function last(string $name): ?array
    {
        if (! Schema::hasTable('heartbeats')) {
            return null;
        }

        $row = DB::table('heartbeats')->where('name', $name)->first();

        if (! $row) {
            return null;
        }

        $at = Carbon::parse($row->beat_at, 'UTC');

        return ['at' => $at, 'note' => $row->note, 'seconds' => max(0, now()->timestamp - $at->timestamp)];
    }
}
