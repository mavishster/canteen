<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\CardException;
use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\Student;
use App\Services\CardService;
use App\Support\Audit\AuditRecorder;
use App\Support\CardUid;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Binds many cards at once from a file: "student number, card number" per line.
 * The file is checked first and nothing changes until the admin confirms. Binding itself is always CardService's job.
 */
class CardImportController extends Controller
{
    private const MAX_ROWS = 2000;

    private const SHOWN = 300;

    public function form(Request $request)
    {
        $this->schoolId($request);

        return view('admin.cards.import');
    }

    /** The students who have no card yet, with an empty card column to fill in. */
    public function template(Request $request)
    {
        $schoolId = $this->schoolId($request);

        AuditRecorder::log(
            'report.exported', 'report', 'Students without a card',
            'List of students without a card exported',
            schoolId: $schoolId
        );

        $withCard = Card::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->whereIn('status', ['active', 'blocked'])
            ->select('student_id');

        $students = Student::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('is_active', true)
            ->whereNotIn('id', $withCard)
            ->orderBy('student_code');

        return response()->streamDownload(function () use ($students) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['student_code', 'card', 'name', 'grade']);

            foreach ($students->cursor() as $student) {
                fputcsv($out, [$this->safeCell($student->student_code), '', $this->safeCell($student->name), $student->grade]);
            }

            fclose($out);
        }, 'students-without-a-card.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function preview(Request $request)
    {
        $schoolId = $this->schoolId($request);

        $request->validate([
            'file' => ['nullable', 'file', 'max:1024'],
            'paste' => ['nullable', 'string', 'max:200000'],
        ]);

        $text = $request->hasFile('file')
            ? (string) file_get_contents($request->file('file')->getRealPath())
            : (string) $request->input('paste', '');

        if (trim($text) === '') {
            return back()->withErrors(['file' => __('Choose a file, or paste the lines.')]);
        }

        $rows = $this->parse($text);

        if (! $rows) {
            return back()->withErrors(['file' => __('The file has no lines to import.')]);
        }

        if (count($rows) > self::MAX_ROWS) {
            return back()->withErrors(['file' => __('At most :count lines can be imported at once.', ['count' => self::MAX_ROWS])]);
        }

        $checked = $this->check($rows, $schoolId);

        // Only the raw lines are kept: they are checked again when the admin confirms
        $token = Str::random(32);
        $request->session()->forget('card_import');
        $request->session()->put("card_import.{$token}", ['rows' => $rows]);

        $counts = collect($checked)->countBy('status');

        return view('admin.cards.preview', [
            'token' => $token,
            'rows' => array_slice($checked, 0, self::SHOWN),
            'total' => count($checked),
            'ready' => (int) ($counts['ok'] ?? 0),
            'counts' => $counts->except('ok')->all(),
            'labels' => collect(array_keys($counts->all()))->mapWithKeys(fn ($status) => [$status => $this->label($status)])->all(),
        ]);
    }

    public function apply(Request $request, CardService $cards)
    {
        $schoolId = $this->schoolId($request);

        $data = $request->validate(['token' => ['required', 'string', 'size:32']]);

        $stored = $request->session()->pull("card_import.{$data['token']}");

        if (! $stored) {
            return redirect()->route('admin.cards.import')->withErrors(['file' => __('This check has expired. Upload the file again.')]);
        }

        // The data may have changed since the check: look again before binding anything
        $checked = $this->check($stored['rows'], $schoolId);

        $bound = 0;
        $skipped = [];
        $failures = [];

        foreach ($checked as $row) {
            if ($row['status'] !== 'ok') {
                $skipped[$row['status']] = ($skipped[$row['status']] ?? 0) + 1;

                continue;
            }

            try {
                $cards->bind($row['student'], $row['card']);
                $bound++;
            } catch (CardException $e) {
                $failures[] = ['line' => $row['line'], 'code' => $row['code'], 'card' => $row['card'], 'message' => $e->getMessage()];
            }
        }

        $skippedCount = array_sum($skipped);

        AuditRecorder::log(
            'cards.imported', 'card', 'Card import',
            "Card import: {$bound} bound, {$skippedCount} skipped, " . count($failures) . ' failed',
            [], ['bound' => $bound, 'skipped' => $skippedCount, 'failed' => count($failures)],
            $schoolId
        );

        return redirect()->route('admin.cards.import.result')->with('card_import_result', [
            'bound' => $bound,
            'skipped' => collect($skipped)->mapWithKeys(fn ($n, $status) => [$this->label($status) => $n])->all(),
            'failures' => $failures,
        ]);
    }

    public function result(Request $request)
    {
        $this->schoolId($request);

        $result = $request->session()->get('card_import_result');

        return $result
            ? view('admin.cards.result', $result)
            : redirect()->route('admin.cards.import');
    }

    // ------------------------------------------------------------------ checking

    /**
     * @param  array<int, array{line: int, code: string, card: string}>  $rows
     * @return array<int, array{line: int, code: string, card: string, status: string, label: string, student: ?Student}>
     */
    private function check(array $rows, int $schoolId): array
    {
        $students = Student::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->whereIn('student_code', collect($rows)->pluck('code')->unique()->all())
            ->get()
            ->keyBy('student_code');

        $uids = collect($rows)->map(fn ($row) => $this->normalize($row['card']))->filter()->unique()->values()->all();

        $registered = Card::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->whereIn('uid', $uids)
            ->pluck('uid')
            ->flip();

        $hasCard = Card::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->whereIn('student_id', $students->pluck('id'))
            ->whereIn('status', ['active', 'blocked'])
            ->pluck('student_id')
            ->flip();

        $seenStudents = [];
        $seenUids = [];
        $out = [];

        foreach ($rows as $row) {
            $uid = $this->normalize($row['card']);
            $student = $students->get($row['code']);

            $status = match (true) {
                $uid === null => 'invalid_card',
                $student === null => 'unknown_student',
                ! $student->is_active => 'inactive_student',
                isset($hasCard[$student->id]) => 'student_has_card',
                isset($registered[$uid]) => 'card_in_use',
                isset($seenStudents[$student->id]) => 'duplicate_student',
                isset($seenUids[$uid]) => 'duplicate_card',
                default => 'ok',
            };

            if ($status === 'ok') {
                $seenStudents[$student->id] = true;
                $seenUids[$uid] = true;
            }

            $out[] = $row + ['status' => $status, 'label' => $this->label($status), 'student' => $status === 'ok' ? $student : null];
        }

        return $out;
    }

    private function label(string $status): string
    {
        return match ($status) {
            'ok' => __('Will be bound'),
            'invalid_card' => __('Card number is not valid.'),
            'unknown_student' => __('No student has that number.'),
            'inactive_student' => __('This student account is inactive.'),
            'student_has_card' => __('This student already has a card.'),
            'card_in_use' => __('This card is already registered to another student.'),
            'duplicate_student' => __('This student appears twice in the file.'),
            'duplicate_card' => __('This card appears twice in the file.'),
            default => $status,
        };
    }

    private function normalize(string $raw): ?string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        if (method_exists(CardUid::class, 'normalize')) {
            try {
                $normalized = CardUid::normalize($raw);

                return ($normalized === null || $normalized === '') ? null : (string) $normalized;
            } catch (Throwable) {
                return null;
            }
        }

        $normalized = strtoupper((string) preg_replace('~[^0-9A-Za-z]~', '', $raw));

        return strlen($normalized) >= 4 ? $normalized : null;
    }

    // ------------------------------------------------------------------- parsing

    /** @return array<int, array{line: int, code: string, card: string}> */
    private function parse(string $text): array
    {
        $text = (string) preg_replace('/^\xEF\xBB\xBF/', '', $text);
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];

        $first = '';
        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $first = $line;

                break;
            }
        }

        // comma, semicolon or tab: whichever the first line uses most
        $delimiter = collect([',', ';', "\t"])->sortByDesc(fn ($d) => substr_count($first, $d))->first();

        $rows = [];

        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            $cells = str_getcsv($line, $delimiter, '"', '\\');
            $code = trim((string) ($cells[0] ?? ''));
            $card = trim((string) ($cells[1] ?? ''));

            // a first line with column titles
            if ($rows === [] && preg_match('~^(student[\s_-]*(code|number|no\.?)|code|id)$~i', $code)) {
                continue;
            }

            $rows[] = ['line' => $index + 1, 'code' => $code, 'card' => $card];
        }

        return $rows;
    }

    // ------------------------------------------------------------------- helpers

    private function schoolId(Request $request): int
    {
        abort_unless($request->user()->school_id, 403, __('Log in as a school admin to import cards.'));

        return (int) $request->user()->school_id;
    }

    /** Stops spreadsheet programs from running a cell as a formula. */
    private function safeCell(mixed $value): mixed
    {
        return is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) ? "'" . $value : $value;
    }
}
