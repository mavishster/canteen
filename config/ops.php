<?php

return [
    'backup' => [
        // where the daily dump is written
        'path' => env('BACKUP_PATH', storage_path('app/backups')),
        // a dump is deleted when it is older than this, but the newest keep_min are always kept
        'keep_days' => (int) env('BACKUP_KEEP_DAYS', 30),
        'keep_min' => 7,
        // canteen:check fails (on a production server) when the last good backup is older than this
        'max_age_hours' => (int) env('BACKUP_MAX_AGE_HOURS', 26),
        // an optional second copy on another disk from config/filesystems.php, such as s3
        'disk' => env('BACKUP_DISK'),
    ],

    // GET /health answers only to a request that sends this in the X-Health-Token header. Empty = the page is off.
    'health_token' => env('HEALTH_TOKEN', ''),
];
