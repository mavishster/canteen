<?php

namespace App\Console\Commands;

use App\Support\I18n\BladeWrapper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Blade;
use Symfony\Component\Finder\Finder;

class I18nWrap extends Command
{
    protected $signature = 'canteen:i18n:wrap {--write : Rewrite the views. Without it, only report what would change.}';

    protected $description = 'Wraps the fixed English text in the views with __() so it can be translated.';

    public function handle(BladeWrapper $wrapper): int
    {
        $files = Finder::create()->files()->in(resource_path('views'))->name('*.blade.php')->sortByName();

        $phrases = 0;
        $changed = 0;
        $failed = [];

        foreach ($files as $file) {
            $source = $file->getContents();
            [$new, $keys] = $wrapper->wrap($source);

            if ($new === $source) {
                continue;
            }

            if (! $this->compiles($new)) {
                $failed[] = $file->getRelativePathname();

                continue;
            }

            $changed++;
            $phrases += count($keys);
            $this->line(sprintf('%5d  %s', count($keys), $file->getRelativePathname()));

            if ($this->option('write')) {
                file_put_contents($file->getRealPath(), $new);
            }
        }

        $this->newLine();
        $this->info(($this->option('write') ? 'Wrapped ' : 'Would wrap ') . "{$phrases} phrase(s) in {$changed} file(s).");

        foreach ($failed as $name) {
            $this->warn("Skipped (the result did not compile, so the file was left as it is): {$name}");
        }

        if ($this->option('write')) {
            $this->call('canteen:i18n:missing', ['--write' => true]);
        } else {
            $this->line('Nothing was changed. Run with --write to rewrite the files (commit first, so you can look at the difference).');
        }

        return self::SUCCESS;
    }

    /** The rewritten view must still turn into valid PHP. */
    private function compiles(string $blade): bool
    {
        try {
            $php = Blade::compileString($blade);
        } catch (\Throwable) {
            return false;
        }

        if (! function_exists('exec')) {
            return true;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'blade');
        file_put_contents($tmp, $php);
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $output, $code);
        @unlink($tmp);

        return $code === 0;
    }
}
