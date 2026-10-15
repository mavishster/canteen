# Meal plans (breakfast, lunch)

- A **meal** (breakfast, lunch) has optional collection hours and weekdays.
- A **plan** is paid time: 1, 3, 6 or 12 months, with its own price and an optional grade range (kindergarten is usually grade 0).
- A **subscription** is one purchase: valid from a date to a date, paid from the wallet through the ledger. No refunds. No counting of meals.
- Renewals chain: a new plan starts the day after the last paid day. At most 12 months may be paid ahead.
- The meal screen (collection) records one meal per student per meal type per day in `meal_consumptions`; the database enforces that.
- Dates are school-time dates.
