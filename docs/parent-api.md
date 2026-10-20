# Parent app API

Base URL: `https://<server>/api/parent`. JSON in, JSON out. Money amounts are integers in minor units of the school's
currency (cents for USD, riel for KHR); `*_formatted` fields are ready to display.

Send `Authorization: Bearer <token>` on every call except login. Parent accounts are created by the school admin.

| Method | Path | What it does |
|---|---|---|
| POST | `/login` | `{email, password, device_name?}` → `{token, user}`. Wrong details → 422. Limited to 5 tries a minute. |
| POST | `/logout` | Deletes this phone's token. |
| GET | `/children` | The parent's children: balance, card status (`active`/`blocked`/null), personal and school-max limits. |
| GET | `/children/{id}/transactions` | Paged history (`?page=2`): top-ups, purchases (with `items`), refunds. Newest first. |
| GET | `/children/{id}/rules` | Limits, current bans, and the catalog (categories, products) to choose bans from. |
| PUT | `/children/{id}/limits` | `{daily_limit, weekly_limit}` in major units (e.g. `2.50`). Send both; empty/null removes a limit. 422 if above the school maximum. |
| POST | `/children/{id}/bans` | `{type: "product"|"category", id}` → 201. |
| DELETE | `/children/{id}/bans/{banId}` | Removes a ban. |
| POST | `/children/{id}/card/block` | Blocks the card at once. |
| POST | `/children/{id}/card/unblock` | Unblocks it. |
| POST | `/children/{id}/topups` | `{amount, currency: "USD"|"KHR"}` → 201 `{ref, status: "pending", checkout_url, ...}`. Open `checkout_url`. 422 below the minimum. 503 if online payments are not available. |
| GET | `/topups/{ref}` | `status`: `pending`, `paid`, `failed`, `review`, `expired`. Poll this after the parent returns from the bank's page. |

Rules of the road:
- The balance only changes after the bank confirms on the server. Never treat a top-up as done because the bank page closed.
- A child that is not the parent's returns 404.
- Errors look like `{"message": "..."}`, with HTTP 401 (not logged in), 403 (not a parent account), 404, 422 or 503.

## Signing in through the school system (SIS)
In production parents do not use a canteen password. The app signs in to the SIS (its own login API, which returns a token),
then sends that token here:

`POST /login-sis` with `{sis_token, device_name?}` returns `{token, user, children}`. Use `token` as the Bearer token for every other call.
- 422 `invalid_token`: the SIS does not accept the token. Sign in to the SIS again.
- 403 `not_allowed`: not an active parent account. 403 `school_unknown`: the school is not set up yet.
- 503 `sis_unavailable`: the SIS cannot be reached. Try again later.
- Any later call can answer 401 `account_disabled`: the SIS says this parent is no longer active (or lost access). Sign in again.

The children list follows the SIS: a child added or removed there appears or disappears here within about 10 minutes.
`POST /login` (email + password) is only for development and for accounts created by the school admin; it is switched off with `SIS_ALLOW_LOCAL_PARENT_LOGIN=false`.

## Notifications
| Method | Path | What it does |
|---|---|---|
| POST | `/devices` | `{token, platform: "ios"\|"android"}`: register this phone for push. Call it at every app start (the token can change). → 201 first time, 200 after. |
| DELETE | `/devices` | `{token}`: stop push to this phone (call it on logout). |
| GET | `/messages` | The message feed, newest first, paged. `unread` is the count of unread messages. Each item: `id, type, title, body, data{student_id,type}, read, created_at`. |
| POST | `/messages/read` | `{ids?: [..]}`: mark those messages (or all, when no ids) as read. |
| GET | `/notification-settings` | `{topup, low_balance, purchase, sms, has_phone, children: [{id, name, low_balance_threshold, effective_threshold}]}`. |
| PUT | `/notification-settings` | Any of `topup`, `low_balance`, `purchase`, `sms` as true/false. |
| PUT | `/children/{id}/low-balance` | `{threshold}` in major units (e.g. `2.50`). Empty = the school's default; `0` = no low-balance warning for this child. |

Message types: `topup` (money arrived), `low_balance` (sent once when the balance drops below the threshold), `purchase` (optional, off by default).
When a push arrives, open the screen for `data.student_id`. The phone number used for SMS is set by the school; the app cannot change it.

## Meal plans (breakfast, lunch)
A plan is paid **time** (1, 3, 6 or 12 months), bought from the child's wallet. There are no refunds, and holidays or absences do not change it.
| Method | Path | What it does |
|---|---|---|
| GET | `/children/{id}/meals` | For each meal: `paid_until` (date or null), `active_now`, and the `plans` this child can buy (`id, name, duration_months, price, price_formatted`). Only plans that fit the child's grade are listed. |
| POST | `/children/{id}/meals/subscribe` | `{plan_id, key}` → 201 `{plan, meal_type, valid_from, expires_on, balance}`. Generate a new random `key` per purchase attempt and reuse it when retrying: the same key never charges twice. 422 reasons: `insufficient_balance`, `plan_unavailable`, `too_far_ahead`, `student_inactive`. |

Buying while a plan is active starts the new one the day after the current one ends. Nobody can be paid up more than 12 months ahead.

## Consent (first sign-in)
When the school requires it, every other call answers `403 {"reason": "consent_required"}` until the parent has accepted the school's newest text.
Show the text and a tick box, then send the version.

| Method | Path | What it does |
|---|---|---|
| GET | `/consent` | `{required, must_accept, version, text, accepted_at}`. Call it after sign-in (or whenever you get `consent_required`). `must_accept` true means: show the text and block the app until accepted. |
| POST | `/consent` | `{version}` the parent accepted. → `{ok, version, accepted_at}`. 409 `version_changed` if the school published a newer text meanwhile: fetch it again. Safe to repeat. |

A new version asks everyone again. The acceptance is stored with the version, the time and the address as evidence.

## Consent (first sign-in)
When the school requires it, every other call answers `403 {"reason": "consent_required"}` until the parent has accepted the school's newest text.
Show the text and a tick box, then send the version.

| Method | Path | What it does |
|---|---|---|
| GET | `/consent` | `{required, must_accept, version, text, accepted_at}`. Call it after sign-in (or whenever you get `consent_required`). `must_accept` true means: show the text and block the app until accepted. |
| POST | `/consent` | `{version}` the parent accepted. → `{ok, version, accepted_at}`. 409 `version_changed` if the school published a newer text meanwhile: fetch it again. Safe to repeat. |

A new version asks everyone again. The acceptance is stored with the version, the time and the address as evidence.
