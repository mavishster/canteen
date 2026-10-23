<?php

use App\Support\I18n\BladeWrapper;
use App\Support\I18n\PhpWrapper;
use Symfony\Component\Finder\Finder;

it('has no plain English text left in the views', function () {
    $wrapper = new BladeWrapper;
    $left = [];

    foreach (Finder::create()->files()->in(resource_path('views'))->name('*.blade.php')->sortByName() as $file) {
        [, $keys] = $wrapper->wrap($file->getContents());

        if ($keys) {
            $left[$file->getRelativePathname()] = $keys;
        }
    }

    expect($left)->toBe([], "Text shown to people must be written as __('...'). Run: php artisan canteen:i18n:wrap --write\n" . json_encode($left, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
});

it('has no plain English message left in the PHP code', function () {
    $wrapper = new PhpWrapper;
    $left = [];

    $files = Finder::create()->files()->in(app_path())->name('*.php')->notName('I18n*.php')->notPath('Support/I18n')->sortByName();

    foreach ($files as $file) {
        [, $keys] = $wrapper->wrap($file->getContents());

        if ($keys) {
            $left[$file->getRelativePathname()] = $keys;
        }
    }

    expect($left)->toBe([], "Messages shown to people must be written as __('...'). Run: php artisan canteen:i18n:wrap-php --write\n" . json_encode($left, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
});

it('keeps the handover notes in the project', function () {
    expect(file_get_contents(base_path('HANDOFF.md')))->toContain('State after step 34')
        ->and(file_get_contents(base_path('AGENTS.md')))->toContain('Translation, menu and audit rules');
});
