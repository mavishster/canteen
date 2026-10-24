<?php

namespace App\Console\Commands;

use App\Support\Ops\Dumper;
use App\Support\Ops\Heartbeat;
use App\Support\Ops\MysqlDumper;
use App\Support\Ops\SqliteDumper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class Backup extends Command
{
    protected $signature = 'canteen:backup';

    protected $description = 'Makes a compressed copy of the whole database, removes old copies, and (if configured) copies it off-site.';

    public function handle(): int
    {
        $dir = rtrim((string) config('ops.backup.path'), '/');

        if (! is_dir($dir) && ! @mkdir($dir, 0750, true) && ! is_dir($dir)) {
            $this->error("Cannot create {$dir}.");

            return self::FAILURE;
        }

        $name = 'canteen-' . now()->format('Ymd-His') . '.sql.gz';
        $partial = "{$dir}/{$name}.partial";
        $final = "{$dir}/{$name}";

        try {
            $this->dumper()->dump($partial);

            if (! is_file($partial) || filesize($partial) < 20) {
                throw new RuntimeException('The backup file is empty.');
            }

            rename($partial, $final);   // a half-written file never has the final name
        } catch (Throwable $e) {
            @unlink($partial);
            report($e);
            $this->error('Backup failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $size = $this->size((int) filesize($final));
        Heartbeat::beat('backup', "{$name}, {$size}");
        $this->info("Backup written: {$final} ({$size}).");

        $removed = $this->prune($dir);

        if ($removed > 0) {
            $this->line("Removed {$removed} old backup(s).");
        }

        return $this->copyOffsite($final, $name);
    }

    private function copyOffsite(string $file, string $name): int
    {
        $disk = config('ops.backup.disk');

        if (! $disk) {
            return self::SUCCESS;
        }

        try {
            $stream = fopen($file, 'rb');
            Storage::disk($disk)->writeStream("canteen-backups/{$name}", $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            Heartbeat::beat('backup_offsite', $name);
            $this->info("Copied to the disk \"{$disk}\".");

            return self::SUCCESS;
        } catch (Throwable $e) {
            report($e);
            $this->error("The off-site copy to \"{$disk}\" failed: " . $e->getMessage());

            return self::FAILURE;
        }
    }

    /** Keeps the newest keep_min, and anything younger than keep_days. Returns how many it removed. */
    private function prune(string $dir): int
    {
        $files = glob("{$dir}/canteen-*.sql.gz") ?: [];
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));   // newest first

        $keepMin = max(1, (int) config('ops.backup.keep_min', 7));
        $limit = now()->subDays(max(1, (int) config('ops.backup.keep_days', 30)))->timestamp;
        $removed = 0;

        foreach (array_slice($files, $keepMin) as $file) {
            if (filemtime($file) < $limit && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    private function dumper(): Dumper
    {
        if (app()->bound('canteen.dumper')) {
            return app('canteen.dumper');   // a test puts its own here
        }

        return match (config('database.connections.' . config('database.default') . '.driver')) {
            'mysql', 'mariadb' => new MysqlDumper,
            'sqlite' => new SqliteDumper,
            default => throw new RuntimeException('Backups support MySQL, MariaDB and SQLite.'),
        };
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, round($bytes / 1024)) . ' KB';
    }
}
