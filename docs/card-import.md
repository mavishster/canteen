# Importing cards (Admin, "Import cards")

1. Download "the list of students without a card": `student_code, card, name, grade`, with the card column empty.
2. In a spreadsheet, click the card cell of a student and tap that student's card on the reader: it types the number. Next student, repeat.
3. Save as CSV, upload (or paste the lines). A check shows, line by line, what will happen. Nothing changes until you confirm.
4. Confirming binds the cards through CardService (the only place that binds cards) and writes each one to the audit log, plus one summary line.

Skipped with a reason: unknown student number, inactive student, student already has a card, card already registered, the same student or card twice in the file, invalid card number.
A card is never replaced by an import: replacing stays a deliberate action on the student's page. Up to 2,000 lines at once; commas, semicolons and tabs all work; a first line with column titles is fine.
