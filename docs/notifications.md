# Notifications to parents

Every ledger entry announces itself (`LedgerEntryRecorded`). `SendWalletNotifications` listens, runs on the queue and only after the
database commit, finds the parents linked to the child, and creates one `parent_messages` row per parent and event
(unique on parent + ledger entry + type, so nothing is ever sent twice). Then it tries push and SMS and records the result:
`push_status` / `sms_status` = sent, failed, no_device, not_configured, skipped. A failure never affects the money.

- **A queue worker must run in production**: `php artisan queue:work` (kept alive by Supervisor or systemd). `composer run dev` already starts one locally.
- **Drivers** (`config/messaging.php`, `.env`): `PUSH_DRIVER` and `SMS_DRIVER`. `log` is for development only (it writes names and balances to the log).
  In production the default is `none`: nothing is sent and messages show `not_configured`. Real drivers (Firebase push, an SMS provider) are added by
  implementing `PushSender` / `SmsSender` and registering the name in `Messengers`.
- **SMS needs a phone number**: `users.phone`. It is set by the school (admin or SIS sync, a later step), never by the app, so nobody can point SMS at someone else's number.
- **Low balance**: default $2 or 8,000 riel, or `settings.notifications.low_balance_default` per school, or per child from the app. Sent once, when the balance crosses the line.
- SMS texts use the child's first name only.
