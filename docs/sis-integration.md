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
