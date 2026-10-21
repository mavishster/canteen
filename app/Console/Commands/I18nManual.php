<?php

namespace App\Console\Commands;

use App\Support\I18n\BladeWrapper;
use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;

class I18nManual extends Command
{
    protected $signature = 'canteen:i18n:manual {--write : Also write the list to lang/manual.txt}';

    protected $description = 'Lists the English text the automatic tools cannot safely convert: text mixed with values, words inside {{ }}, and JavaScript strings.';

    public function handle(BladeWrapper $wrapper): int
    {
        $lines = [];

        $views = Finder::create()->files()->in(resource_path('views'))->name('*.blade.php')->sortByName();

        foreach ($views as $file) {
            [, , $manual] = $wrapper->wrap($file->getContents());

            foreach (collect($manual)->unique(fn ($m) => $m[0] . '|' . $m[1]) as [$kind, $text]) {
                $lines[] = "[{$kind}] views/{$file->getRelativePathname()}: {$text}";
            }
        }

        if (is_dir(resource_path('js'))) {
            $scripts = Finder::create()->files()->in(resource_path('js'))->name('*.js')->sortByName();

            foreach ($scripts as $file) {
                foreach ($wrapper->jsStrings($file->getContents()) as $string) {
                    $lines[] = "[js] js/{$file->getRelativePathname()}: {$string}";
                }
            }
        }

        foreach ($lines as $line) {
            $this->line($line);
        }

        $counts = collect($lines)->countBy(fn ($l) => substr($l, 1, strpos($l, ']') - 1));
        $this->newLine();
        $this->info(count($lines) . ' place(s) for a person: ' . ($counts->map(fn ($n, $k) => "{$n} {$k}")->implode(', ') ?: 'none') . '.');

        if ($this->option('write')) {
            file_put_contents(base_path('lang/manual.txt'), implode("\n", $lines) . "\n");
            $this->line('Written to lang/manual.txt');
        }

        return self::SUCCESS;
    }
}
