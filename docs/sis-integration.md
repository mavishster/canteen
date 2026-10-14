# SIS integration

The canteen reads students and their RFID numbers from the SIS (a separate server and database). It never writes to the SIS.

## What is read
`users` (students only), `students_classroom`, `classrooms`, `academic_year`, and later `student_parents`.
Only these columns are ever selected: `users.id, name, rfid, user_type, user_status, branch_id`; `students_classroom.student_id,
classroom_id, academic_year_id`; `classrooms.classroom_id, grade_level`; `academic_year.academic_year_id, is_active, deleted_at`.
A student is "current" when `user_type = 'student'`, `user_status = 1`, `branch_id` = the school's `sis_branch_id`, and they
are in a classroom of the academic year with `is_active = 1`.

## Read-only database user (ask the SIS administrator)
Column-level grants mean that even a coding mistake cannot read a password: a query that touches any other column is refused.

```sql
CREATE USER 'canteen_ro'@'<CANTEEN_SERVER_IP>' IDENTIFIED BY '<long random password>' REQUIRE SSL;

GRANT SELECT (id, name, rfid, user_type, user_status, branch_id) ON sis_db.users TO 'canteen_ro'@'<CANTEEN_SERVER_IP>';
GRANT SELECT (sc_id, academic_year_id, classroom_id, student_id)  ON sis_db.students_classroom TO 'canteen_ro'@'<CANTEEN_SERVER_IP>';
GRANT SELECT (classroom_id, grade_level)                          ON sis_db.classrooms TO 'canteen_ro'@'<CANTEEN_SERVER_IP>';
GRANT SELECT (academic_year_id, is_active, deleted_at)            ON sis_db.academic_year TO 'canteen_ro'@'<CANTEEN_SERVER_IP>';
-- for parent login later:
GRANT SELECT (student_id, parent_id, relation_type)               ON sis_db.student_parents TO 'canteen_ro'@'<CANTEEN_SERVER_IP>';
```
Allow the MySQL port only from the canteen server's IP (firewall or VPN), never from the internet. Never expose
`password`, `initial_password`, `remember_token`, `password_reset_token`, `usb_key`, `signature`, `tmp`, `biometric_id` or the `data_*` columns.

## Set up (development, with a local copy of the SIS)
1. In `.env`: `SIS_DB_HOST`, `SIS_DB_PORT`, `SIS_DB_DATABASE`, `SIS_DB_USERNAME`, `SIS_DB_PASSWORD`. Keep real students' data on your own computer only.
2. Link the school to its SIS branch: `School::where('code', 'PILOT')->update(['sis_branch_id' => <branches.branch_id>]);`
3. Check the card format with one real card: `php artisan sis:card-formats 0001035627`, compare with what the canteen reader types, set `SIS_CARD_FORMAT`.
4. Rehearse: `php artisan sis:import-students PILOT --dry-run`. Nothing is saved. Read the report.
5. Run it for real: `php artisan sis:import-students PILOT`. Run it again whenever the SIS changes (it is safe to repeat).

## What the import does
- Creates a student (with an empty account) for every current SIS student, `student_code` = the SIS student ID. Renames and grade changes are applied.
- Binds the card if the student has none; replaces it if the SIS has a new number (yearly renewal or lost card). The balance always stays.
- Never unblocks a card a parent blocked. Never takes a card from another student. Never deletes or deactivates anyone.
- Reports, for a person to fix: `no_card`, `invalid_card`, `duplicate_card` (same RFID on two SIS students), `card_in_use`, `card_retired`,
  and `not_in_sis` (active in the canteen but not listed by the SIS: check for a balance before deactivating).
- Refuses to run when the SIS returns no students at all (assumed outage).

## Parent login (Step 17)
1. The app signs in to the SIS and gets a SIS token (Sanctum). The canteen never sees the parent's password.
2. The app sends the token to `POST /api/parent/login-sis`.
3. The canteen asks the SIS API who owns the token (`SIS_API_URL` + `SIS_API_USER_PATH`, default `/api/user`). Only the `id` in the answer is read:
   set `SIS_API_USER_WRAPPER` if the id is nested (e.g. `data`) and `SIS_API_USER_ID_FIELD` if it is not called `id`. Use https in production.
4. Everything else is read from the SIS database (read-only): `users` (must be `user_type = 'parent'`, `user_status = 1`, with a `branch_id` that matches a school's
   `sis_branch_id`) and `student_parents` (the children). Children are matched to canteen students by `student_code` = SIS student id, inside the parent's school only.
5. The canteen creates a local parent account (no usable password), links the children, and issues its own token.

Every parent API call re-checks the SIS at most every `SIS_LINK_CACHE_MINUTES` (10). If the SIS says the parent is inactive or gone, all their canteen tokens and child links are removed.
If the SIS cannot be reached, parents keep working on the saved links for `SIS_LINK_GRACE_MINUTES` (60), then get 503 (fail closed). New logins need the SIS.

Rules: never log or store the SIS token. Parents created by the school admin (Step 15) are not touched by any of this.
In production set `SIS_ALLOW_LOCAL_PARENT_LOGIN=false` so only the SIS login works. A parent from the SIS appears on the admin Parents page; do not set a password for them there.

## Parents' phone numbers (Step 20)
For SMS alerts the canteen can copy each parent's `users.mobile_phone` from the SIS. It is OFF by default (`SIS_SYNC_PHONE=false`).
1. Ask the SIS administrator to add one column to the canteen's read-only grant:
   `GRANT SELECT (mobile_phone) ON <sis_db>.users TO 'canteen_ro'@'<CANTEEN_SERVER_IP>';`
2. Check the data (prints counts and shapes only, never a number): `php artisan sis:check-phones PILOT`
3. Set `SIS_SYNC_PHONE=true`. Numbers are cleaned to `+855...`; anything doubtful is dropped (no SMS) rather than guessed.
Numbers are copied at login and at every refresh (about every 10 minutes). The SIS is the source of truth: if it removes a number, SMS to that parent stops.
Do NOT turn it on before the grant exists: every parent lookup would fail and parents could not sign in.
