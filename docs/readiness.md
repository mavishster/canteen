# Go-live readiness

Run before the first real day, and again after every change to `.env` or the server:

    php artisan canteen:check

- **FAIL** lines must be fixed before any real student, parent or payment is used. The command exits with an error while any FAIL remains,
  so it can gate a deployment script.
- **WARN** lines should be fixed in the first week.
- `--json` prints the same results for a monitoring tool.

It checks configuration and data only; it never changes anything and never prints secrets or personal data.
Items that need a person (a real SMS provider, the school's SIS grant, the cron entry for the scheduler) are reported as reminders.
