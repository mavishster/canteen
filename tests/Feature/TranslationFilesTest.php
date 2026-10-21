<?php

it('has a valid Khmer file with no blank translation', function () {
    $km = json_decode(file_get_contents(base_path('lang/km.json')), true);

    expect($km)->toBeArray()->not->toBeEmpty();

    foreach ($km as $phrase => $khmer) {
        // a blank value would show an empty label instead of falling back to English
        expect(trim((string) $khmer))->not->toBe('', "blank Khmer for: {$phrase}");
    }
});

it('keeps the :placeholders of a phrase in its Khmer', function () {
    $km = json_decode(file_get_contents(base_path('lang/km.json')), true);

    expect($km)->toBeArray();

    foreach ($km as $phrase => $khmer) {
        preg_match_all('/:[a-z_]+/', (string) $phrase, $in);

        foreach ($in[0] as $placeholder) {
            expect($khmer)->toContain($placeholder, "the Khmer for \"{$phrase}\" lost {$placeholder}");
        }
    }
});

it('shows the Khmer for the wrapped phrases', function () {
    app()->setLocale('km');

    expect(__('Add product'))->toBe('បន្ថែមទំនិញ')
        ->and(__('Selling hours'))->toBe('ម៉ោងលក់')
        ->and(__('No sales yet.'))->toBe('មិនទាន់មានការលក់នៅឡើយទេ។');
});
