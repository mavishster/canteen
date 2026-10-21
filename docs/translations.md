# Translations (English, Khmer)

Which language a person sees: `?lang=km` on the address, then the choice they saved (the menu switch, or the app), then this browser session,
then the language cookie, then (the parent app only) the Accept-Language header, then APP_LOCALE in .env. Set APP_LOCALE=km for a school whose staff mostly read Khmer.

Fixed text is written in English inside `__('...')`; the Khmer is in `lang/km.json` (English phrase => Khmer). A phrase with no Khmer entry shows in English.

## Adding the Khmer text
1. `php artisan canteen:i18n:wrap` shows what would be wrapped; `--write` rewrites the views (commit first). It leaves alone text mixed with values, scripts, and anything it is unsure about.
2. `php artisan canteen:i18n:missing --write` writes `lang/km.todo.json`: every phrase with no Khmer yet.
3. Fill in the Khmer, merge it into `lang/km.json`, run again: the todo list shrinks to nothing.
New text written later as `__('...')` appears in the list the same way.

Khmer text must be read by a native speaker before real use, above all the words about money, refunds and parents' rights.
Messages from the server (errors the till shows, answers to the parent app) are translated in a later stage.
