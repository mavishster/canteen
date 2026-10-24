<?php

namespace App\Support\Ops;

/**
 * Is the system being looked after? Used by `canteen:check` and by GET /health.
 * Never seen yet is only a warning (a fresh install); seen but too long ago is a FAIL on a production server.
 */
final class OperationsStatus
{
    private const SCHEDULER_MAX_SECONDS = 180;     // it beats every minute

    private const QUEUE_MAX_SECONDS = 720;         // a job is sent every 5 minutes

    /** @return array<int, array{level: string, area: string, message: string}> */
    public static function report(bool $production): array
    {
        $bad = $production ? 'FAIL' : 'WARN';
        $out = [];

        // ---- scheduler
        $scheduler = Heartbeat::last('scheduler');

        if (! $scheduler) {
            $out[] = self::line('WARN', 'Scheduler', 'Not seen yet. The server must run `* * * * * php artisan schedule:run` every minute (see docs/operations.md).');
        } elseif ($scheduler['seconds'] <= self::SCHEDULER_MAX_SECONDS) {
            $out[] = self::line('OK', 'Scheduler', 'Running (last beat ' . self::ago($scheduler['seconds']) . ').');
        } else {
            $out[] = self::line($bad, 'Scheduler', 'Not running: the last beat was ' . self::ago($scheduler['seconds']) . '. Top-ups are not reconciled and plans do not expire.');
        }

        // ---- queue worker (with the sync queue there is no worker to check)
        if (config('queue.default') !== 'sync') {
            $queue = Heartbeat::last('queue');

            if (! $queue) {
                $out[] = self::line('WARN', 'Queue worker', 'Not seen yet. `php artisan queue:work` must run all the time (Supervisor, see docs/operations.md).');
            } elseif ($queue['seconds'] <= self::QUEUE_MAX_SECONDS) {
                $out[] = self::line('OK', 'Queue worker', 'Working (last job ' . self::ago($queue['seconds']) . ').');
            } else {
                $out[] = self::line($bad, 'Queue worker', 'Not working: the last job was ' . self::ago($queue['seconds']) . '. Parents are not being told about top-ups and purchases.');
            }
        }

        // ---- backup
        $maxHours = max(1, (int) config('ops.backup.max_age_hours', 26));
        $backup = Heartbeat::last('backup');

        if (! $backup) {
            $out[] = self::line('WARN', 'Backup', 'No backup has been made yet. Run `php artisan canteen:backup`; it is scheduled daily at 02:00.');
        } elseif ($backup['seconds'] <= $maxHours * 3600) {
            $out[] = self::line('OK', 'Backup', 'Last good backup ' . self::ago($backup['seconds']) . ($backup['note'] ? " ({$backup['note']})." : '.'));
        } else {
            $out[] = self::line($bad, 'Backup', 'The last good backup was ' . self::ago($backup['seconds']) . ". Check why `canteen:backup` is not working.");
        }

        if (config('ops.backup.disk')) {
            $offsite = Heartbeat::last('backup_offsite');

            if (! $offsite || $offsite['seconds'] > $maxHours * 3600) {
                $out[] = self::line('WARN', 'Backup copy', 'The off-site copy on disk "' . config('ops.backup.disk') . '" is missing or old.');
            } else {
                $out[] = self::line('OK', 'Backup copy', 'Off-site copy ' . self::ago($offsite['seconds']) . '.');
            }
        }

        // ---- disk space (a warning only: a small disk is not an emergency by itself)
        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());

        if ($free !== false && $total) {
            $percent = (int) round($free / $total * 100);
            $gb = round($free / 1024 ** 3, 1);

            $out[] = ($free < 2 * 1024 ** 3 || $percent < 10)
                ? self::line('WARN', 'Disk', "Only {$gb} GB free ({$percent}%). Free some space before backups and logs fill it.")
                : self::line('OK', 'Disk', "{$gb} GB free ({$percent}%).");
        }

        return $out;
    }

    private static function line(string $level, string $area, string $message): array
    {
        return ['level' => $level, 'area' => $area, 'message' => $message];
    }

    private static function ago(int $seconds): string
    {
        return match (true) {
            $seconds < 90 => 'a moment ago',
            $seconds < 5400 => round($seconds / 60) . ' minutes ago',
            $seconds < 172800 => round($seconds / 3600) . ' hours ago',
            default => round($seconds / 86400) . ' days ago',
        };
    }
}
