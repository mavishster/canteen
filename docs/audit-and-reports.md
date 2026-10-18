# Audit log and money reports

## Audit log (Admin, "Audit log")
Records who did what, when, from which address, with the before and after values. It cannot be edited or erased.
Recorded: products (price, name, hidden, category), categories, meals and meal plans, plans sold, school settings (each changed key),
spending limits and low-balance thresholds, cards (bound, blocked, unblocked, retired; the number is masked), bans, parent links, refunds,
staff (created, role changed, disabled, enabled, password changed without the password), students, sign-ins and failed sign-ins, report exports.
Not recorded: every sale (the money ledger has them), stock movements (they have their own history), wallet balances, and work done by the system with no person (SIS import).
A problem in the audit never stops the action it records (it is only reported to the error log).

## Reports (Manager)
- **Top-ups**: paid and unpaid top-ups for a period, per school day, per currency, per status; CSV.
- **Balances**: money held in all student wallets now (active students, and students who left), by grade, below the low-balance line,
  the largest balances, and how the total moved over a period (opening + top-ups - purchases + refunds = closing); CSV.
Every CSV export is written to the audit log.
