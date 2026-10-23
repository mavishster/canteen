#!/usr/bin/env bash
# Collects the code needed for the last translation stage into /tmp/for-claude.txt (and the clipboard on a Mac).
# It only READS: no passwords, no data, just code and the list of phrases still without Khmer.
# Run from the project root:  bash collect-for-claude.sh
set -uo pipefail

[ -f artisan ] || { echo "Run this from the project root (the folder with 'artisan')."; exit 1; }

out=/tmp/for-claude.txt
: > "$out"

dump() {
    echo "=== $1" >> "$out"
    if [ -f "$1" ]; then cat "$1" >> "$out"; else echo "(not found)" >> "$out"; fi
    echo >> "$out"
}

# the exceptions (words that arrive inside placeholders) and everything that sends notifications
for f in app/Exceptions/*.php app/Listeners/SendWalletNotifications.php app/Services/Notifications/*.php; do
    dump "$f"
done

# the JavaScript of the till, the meal screen and the student screen
for f in resources/views/till/index.blade.php resources/views/till/meals.blade.php resources/views/admin/students/index.blade.php; do
    echo "=== $f (scripts)" >> "$out"
    if [ -f "$f" ]; then
        perl -0ne 'print "$&\n" while /<script\b.*?<\/script>/gs' "$f" >> "$out" 2>/dev/null
    else
        echo "(not found)" >> "$out"
    fi
    echo >> "$out"
done

# where a few placeholders get their values
echo "=== where some placeholders get their values" >> "$out"
grep -rn -B2 -A2 -e "gateway_ref: :status" -e "(:window)" -e ":paidUntil" -e "No active :meal" -e ":period" app >> "$out" 2>/dev/null
echo >> "$out"

echo "=== still without Khmer" >> "$out"
[ -f lang/km.todo.json ] && cat lang/km.todo.json >> "$out"

echo "Collected $(wc -c < "$out" | tr -d ' ') bytes in $out"

if command -v pbcopy >/dev/null 2>&1; then
    pbcopy < "$out"
    echo "Copied to the clipboard: paste it into your reply with Cmd+V."
else
    echo "Open $out, select all, copy, and paste it into your reply."
fi