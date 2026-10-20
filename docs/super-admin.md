# Schools (super-admin)

Super-admin menu, "Schools". A super-admin belongs to no school; after signing in they land on the list.
- List: students (active / all), parents, staff, money held in the wallets, last money activity, per school.
- New school: name, short code, SIS branch number, currency, time zone, and optionally the first admin.
- School page: details, look for the app (name, colour, contact: `GET /api/parent/school`), staff, adding an admin, and a new password for anyone on the staff.
- Currency and time zone are editable only while the school has no students and no money.
Everything is written to the audit log. Not here yet: suspending a school, and a payment account per school (that comes with the ABA driver).
