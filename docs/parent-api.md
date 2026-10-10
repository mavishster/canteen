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
