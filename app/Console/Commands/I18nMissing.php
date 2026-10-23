<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;

class I18nMissing extends Command
{
    protected $signature = 'canteen:i18n:missing {--write : Write the list to lang/km.todo.json}';

    protected $description = 'Lists the phrases used in the views and the code that have no Khmer translation yet.';

    public function handle(): int
    {
        $used = $this->usedPhrases();
        $translated = $this->readJson(base_path('lang/km.json'));
        $missing = array_values(array_diff($used, array_keys($translated)));
        sort($missing);

        $this->info(count($used) . ' phrase(s) in use, ' . (count($used) - count($missing)) . ' translated, ' . count($missing) . ' still missing.');

        if ($this->option('write')) {
            $todo = array_fill_keys($missing, '');
            file_put_contents(
                base_path('lang/km.todo.json'),
                json_encode($todo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
            );
            $this->line('Written to lang/km.todo.json: send it to be translated.');
        }

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private function usedPhrases(): array
    {
        $phrases = [];

        $finder = Finder::create()->files()->in([resource_path('views'), app_path()])->name('*.php')->notName('I18n*.php')->notPath('Support/I18n');

        foreach ($finder as $file) {
            $text = $file->getContents();

            foreach (['~(?:__|trans|@lang)\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'~', '~(?:__|trans|@lang)\(\s*"((?:[^"\\\\]|\\\\.)*)"~'] as $pattern) {
                if (preg_match_all($pattern, $text, $m)) {
                    foreach ($m[1] as $found) {
                        $phrases[] = stripslashes($found);
                    }
                }
            }
        }

        // The menu labels live in config/menu.php, not in a __() call
        foreach ((array) config('menu.items', []) as $item) {
            $phrases[] = (string) ($item['label'] ?? '');
            $phrases[] = (string) ($item['group'] ?? '');
        }
        array_push($phrases, 'Admin', 'Administration', 'Reports', 'Language');

        return collect($phrases)
            ->filter(fn ($p) => $p !== '')
            ->reject(fn ($p) => preg_match('~^[a-z_]+(\.[a-z_]+)+$~', $p))   // a key of a PHP language file, such as validation.required
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<string, string> */
    private function readJson(string $path): array
    {
        return is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
    }
}
