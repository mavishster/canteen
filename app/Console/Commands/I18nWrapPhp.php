<?php

namespace App\Console\Commands;

use App\Support\I18n\PhpWrapper;
use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;

class I18nWrapPhp extends Command
{
    protected $signature = 'canteen:i18n:wrap-php {--write : Rewrite the files. Without it, only report what would change.}';

    protected $description = 'Wraps the messages the server produces (flash messages, errors, JSON answers, exception texts) with __().';

    public function handle(PhpWrapper $wrapper): int
    {
        $files = Finder::create()->files()->in(app_path())->name('*.php')
            ->notName('I18n*.php')->notPath('Support/I18n')->sortByName();

        $phrases = 0;
        $changed = 0;
        $failed = [];
        $manual = [];

        foreach ($files as $file) {
            $source = $file->getContents();
            [$new, $keys, $skipped] = $wrapper->wrap($source);

            foreach ($skipped as $item) {
                $manual[] = "{$file->getRelativePathname()}:{$item['line']}  {$item['text']}";
            }

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
        $this->info(($this->option('write') ? 'Wrapped ' : 'Would wrap ') . "{$phrases} message(s) in {$changed} file(s).");

        foreach ($failed as $name) {
            $this->warn("Skipped (the result was not valid PHP, so the file was left as it is): {$name}");
        }

        if ($manual) {
            $this->newLine();
            $this->warn(count($manual) . ' place(s) left for a person:');
            foreach ($manual as $line) {
                $this->line('  ' . $line);
            }
        }

        if ($this->option('write')) {
            file_put_contents(base_path('lang/php-manual.txt'), implode("\n", $manual) . "\n");
            $this->line('The list for a person is in lang/php-manual.txt');
            $this->call('canteen:i18n:missing', ['--write' => true]);
        } else {
            $this->line('Nothing was changed. Run with --write to rewrite the files (commit first, so you can look at the difference).');
        }

        return self::SUCCESS;
    }

    private function compiles(string $php): bool
    {
        if (! function_exists('exec')) {
            return true;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'phpw');
        file_put_contents($tmp, $php);
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $output, $code);
        @unlink($tmp);

        return $code === 0;
    }
}
