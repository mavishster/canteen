
## State after step 34

### What exists, by area
- **Money, cards, till, rules, stock, refunds, top-ups, parent API, SIS, notifications, meal plans and the meal screen**: steps 1-24 (see the sections above).
- **School settings page** (per-purchase limit, smallest top-up, riel rate, low-balance default, selling by student number) and **product categories as a managed list** with merge: step 25.
- **Audit log** (`/admin/audit`, `App\Support\Audit\AuditRecorder`) and **money reports** (manager: top-ups, wallet balances with roll-forward): step 26.
- **Parental consent** (versioned texts, acceptance records, API gate): step 27. **Selling hours per category**: step 28. **Meals report**: step 29.
- **Super-admin school management** (list, create with first admin, branding, password reset): step 30. Parent API: `GET /api/parent/school`.
- **Role-aware top menu**, driven by `config/menu.php` and each route's own `role:` middleware: step 31.
- **Translations (English, Khmer)**: steps 32-34. Language switch in the menu, saved per user (`users.locale`); parent app sends `Accept-Language`.

### Tables added since step 24
`audit_logs` (append-only), `consent_documents` and `parent_consents` (append-only), `users.locale`, `categories.is_active`, `categories.sell_from/sell_to/sell_days`.

### Rules that must never be broken (in addition to the ones above)
1. Every new piece of text a person reads is written as `__('English text')`: in Blade, in PHP messages (flash messages, errors, JSON `message`, exceptions) and in JavaScript (`__('...')`, provided by `layouts/i18n-js`). Values go in `:placeholders`: `__('Hello :name', ['name' => $name])`.
   Then run `php artisan canteen:i18n:missing --write` and add the Khmer to `lang/km.json`. The guard tests in `tests/Feature/TranslationGuardTest.php` fail on plain English left in a view or a message.
2. Menu: add a page with one line in `config/menu.php`. Who sees it comes from the route's `role:` middleware. Never hide or show links with role checks in Blade.
3. Audit: a model whose changes an admin should be accountable for goes in `AuditRecorder::registry()`; anything that is not a model change uses `AuditRecorder::log(...)`. Never record a password, a full card number or a token.
4. Parent API: a call that must work before the consent text is accepted goes in the `routeIs(...)` list in `EnsureParent` (today: consent, logout, school, language).
5. Published consent texts and recorded acceptances, audit entries and ledger entries are never edited or deleted.

### Deploying an update
`git pull`, `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build`, `php artisan migrate --force`, `php artisan config:cache && php artisan route:cache && php artisan view:cache`, `php artisan queue:restart`, then `php artisan canteen:check`.
Set `APP_LOCALE=km` in `.env` for a school whose staff mostly read Khmer.

### Still open (outside the code, or waiting for data)
- ABA PayWay: credentials and API documentation; then the real driver (`AbaPayWayGateway::IMPLEMENTED = true`) and auto top-up.
- Push and SMS providers; the SIS login API address and token format; the card reader model and the real card number format (`SIS_CARD_FORMAT`).
- The parent mobile app (the API is ready: `docs/parent-api.md`).
- A native Khmer speaker must read `lang/km.json` and `lang/km/*.php`, above all the words about money, refunds and parents' rights, and the school must have its consent text reviewed by a legal adviser.
- Translation leftovers: JavaScript sentences built from pieces, English words that arrive inside placeholders (role names, daily/weekly), and the push/SMS texts (they need the parent's saved language). `php artisan canteen:i18n:manual --write` lists what is left to convert by hand.
