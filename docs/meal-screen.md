# Meal screen (breakfast and lunch)

Staff open **Meal screen**, choose the meal (it is remembered on that computer; "Choose the meal by time of day" resets it),
and the card reader does the rest. Each result is a big green OK with the child's first name, or a red reason, and clears itself after a few seconds.

Rules, in the order they are checked:
1. The school is not closed that day (Meals page, section 4).
2. The meal is served on that weekday.
3. The time is inside the meal's collection hours (school time), if hours are set.
4. The child is active, and a typed number has been confirmed by the staff member; a blocked card is refused.
5. The child has not already been served this meal today. The database enforces this with a unique index on (child, meal, day).
6. The child has an active plan for this meal that covers today.

Nothing is charged at the screen: the money was paid when the plan was bought. Every successful tap is stored with who served it and whether it was a card or a typed number.
Refused taps are not stored; the reason is shown on the screen.
Counts shown are today's meals per meal type, in school time.
