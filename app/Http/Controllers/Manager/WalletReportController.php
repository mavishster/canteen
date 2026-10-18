<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\LedgerEntry;
use App\Models\School;
use App\Models\Topup;
use App\Services\Notifications\LowBalance;
use App\Support\Audit\AuditRecorder;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Money in and money held: what parents paid in, and what is left on the wallets. */
class WalletReportController extends Controller
{
    private const STATUSES = ['paid', 'pending', 'failed', 'review', 'expired'];

    // --------------------------------------------------------------- top-ups

    public function topups(Request $request)
    {
        Paginator::useBootstrapFive();

        [$school, $from, $to, $fromUtc, $toUtc] = $this->range($request);
        $request->validate(['status' => ['nullable', Rule::in(self::STATUSES)]]);
        $status = $request->input('status');
        $timezone = $school->timezone;

        $base = $this->topupBase($school, $fromUtc, $toUtc);

        $byStatus = (clone $base)->selectRaw('status, count(*) as n, sum(amount) as credited')->groupBy('status')->get()->keyBy('status');

        $byCurrency = (clone $base)->where('status', 'paid')
            ->selectRaw('pay_currency, count(*) as n, sum(pay_amount) as paid, sum(amount) as credited')
            ->groupBy('pay_currency')
            ->orderBy('pay_currency')
            ->get();

        // Per school day (not per UTC day): a payment at 01:30 local time belongs to that local day
        $days = [];
        (clone $base)->where('status', 'paid')->select(['paid_at', 'amount'])->orderBy('id')->cursor()
            ->each(function ($t) use (&$days, $timezone) {
                $day = Carbon::parse($t->paid_at)->timezone($timezone)->toDateString();
                $days[$day]['count'] = ($days[$day]['count'] ?? 0) + 1;
                $days[$day]['credited'] = ($days[$day]['credited'] ?? 0) + (int) $t->amount;
            });
        ksort($days);

        $topups = (clone $base)
            ->with('student:id,student_code,name')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByRaw('coalesce(paid_at, created_at) desc')
            ->paginate(50)
            ->withQueryString();

        return view('manager.reports.topups', [
            'school' => $school,
            'currency' => $school->currency,
            'from' => $from,
            'to' => $to,
            'status' => $status,
            'statuses' => self::STATUSES,
            'byStatus' => $byStatus,
            'byCurrency' => $byCurrency,
            'days' => $days,
            'topups' => $topups,
        ]);
    }

    public function topupsCsv(Request $request)
    {
        [$school, $from, $to, $fromUtc, $toUtc] = $this->range($request);

        AuditRecorder::log(
            'report.exported', 'report', 'Top-ups',
            "Top-ups report exported ({$from->toDateString()} to {$to->toDateString()})",
            schoolId: $school->id
        );

        $query = $this->topupBase($school, $fromUtc, $toUtc)->with('student:id,student_code')->orderBy('id');

        return response()->streamDownload(function () use ($query, $school) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['time', 'student_code', 'gateway', 'gateway_ref', 'gateway_txn_id', 'status', 'pay_currency', 'pay_amount', 'credited_currency', 'credited_amount']);

            $query->chunkById(500, function ($rows) use ($out, $school) {
                foreach ($rows as $t) {
                    fputcsv($out, array_map([$this, 'safeCell'], [
                        ($t->paid_at ?? $t->created_at)->timezone($school->timezone)->format('Y-m-d H:i:s'),
                        $t->student->student_code,
                        $t->gateway,
                        $t->gateway_ref,
                        $t->gateway_txn_id,
                        $t->status,
                        $t->pay_currency,
                        Money::toMajor($t->pay_amount, $t->pay_currency),
                        $school->currency,
                        Money::toMajor($t->amount, $school->currency),
                    ]));
                }
            });

            fclose($out);
        }, "topups-{$from->toDateString()}-to-{$to->toDateString()}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // -------------------------------------------------------------- balances

    public function balances(Request $request)
    {
        [$school, $from, $to, $fromUtc, $toUtc] = $this->range($request);

        $accounts = fn () => Account::withoutGlobalScopes()->where('school_id', $school->id);

        $joined = fn () => DB::table('accounts')
            ->join('students', 'students.id', '=', 'accounts.student_id')
            ->where('accounts.school_id', $school->id);

        $total = (int) $accounts()->sum('balance');
        $count = $accounts()->count();
        $zero = $accounts()->where('balance', 0)->count();

        $split = $joined()
            ->selectRaw('students.is_active as active, count(*) as n, sum(accounts.balance) as total')
            ->groupBy('students.is_active')
            ->get()
            ->keyBy(fn ($row) => (int) $row->active);

        $byGrade = $joined()
            ->selectRaw('students.grade as grade, count(*) as students, sum(accounts.balance) as total')
            ->groupBy('students.grade')
            ->orderBy('students.grade')
            ->get();

        $largest = $joined()
            ->orderByDesc('accounts.balance')
            ->limit(20)
            ->get(['students.student_code', 'students.name', 'students.grade', 'accounts.balance']);

        $line = LowBalance::thresholdFor(null, $school);
        $belowLine = $line > 0
            ? $joined()->where('students.is_active', 1)->where('accounts.balance', '<', $line)->count()
            : 0;

        // How the total moved over the period. It always adds up: opening + movements = closing.
        $ledger = fn () => LedgerEntry::withoutGlobalScopes()->where('school_id', $school->id);
        $opening = (int) $ledger()->where('created_at', '<', $fromUtc->toDateTimeString())->sum('amount');
        $closing = (int) $ledger()->where('created_at', '<=', $toUtc->toDateTimeString())->sum('amount');
        $moves = $ledger()
            ->whereBetween('created_at', [$fromUtc->toDateTimeString(), $toUtc->toDateTimeString()])
            ->selectRaw('type, count(*) as n, sum(amount) as total')
            ->groupBy('type')
            ->get()
            ->keyBy(fn ($row) => $row->type->value);

        return view('manager.reports.balances', [
            'school' => $school,
            'currency' => $school->currency,
            'from' => $from,
            'to' => $to,
            'total' => $total,
            'count' => $count,
            'zero' => $zero,
            'split' => $split,
            'byGrade' => $byGrade,
            'largest' => $largest,
            'line' => $line,
            'belowLine' => $belowLine,
            'opening' => $opening,
            'closing' => $closing,
            'moves' => $moves,
        ]);
    }

    /** Who holds how much right now (student number, grade, balance). Names are left out on purpose. */
    public function balancesCsv(Request $request)
    {
        [$school] = $this->range($request);

        AuditRecorder::log(
            'report.exported', 'report', 'Wallet balances',
            'Wallet balances exported (one line per student)',
            schoolId: $school->id
        );

        $rows = DB::table('accounts')
            ->join('students', 'students.id', '=', 'accounts.student_id')
            ->where('accounts.school_id', $school->id)
            ->orderBy('students.student_code')
            ->select('students.student_code', 'students.grade', 'students.is_active', 'accounts.balance');

        return response()->streamDownload(function () use ($rows, $school) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['student_code', 'grade', 'active', 'currency', 'balance']);

            foreach ($rows->cursor() as $row) {
                fputcsv($out, array_map([$this, 'safeCell'], [
                    $row->student_code,
                    $row->grade,
                    $row->is_active ? 'yes' : 'no',
                    $school->currency,
                    Money::toMajor((int) $row->balance, $school->currency),
                ]));
            }

            fclose($out);
        }, 'wallet-balances-' . now($school->timezone)->toDateString() . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ----------------------------------------------------------------- helpers

    private function topupBase(School $school, Carbon $fromUtc, Carbon $toUtc)
    {
        // A paid top-up counts on the day it was paid; one that was not paid, on the day it was started
        return Topup::withoutGlobalScopes()
            ->where('school_id', $school->id)
            ->whereRaw('coalesce(paid_at, created_at) between ? and ?', [$fromUtc->toDateTimeString(), $toUtc->toDateTimeString()]);
    }

    /** @return array{0: School, 1: Carbon, 2: Carbon, 3: Carbon, 4: Carbon} school, first day, last day (school time), and the UTC start and end */
    private function range(Request $request): array
    {
        abort_unless($request->user()->school_id, 403, 'Log in as a school user to see reports.');

        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $school = School::findOrFail($request->user()->school_id);
        $timezone = $school->timezone;

        $to = $request->filled('to')
            ? Carbon::parse($request->input('to'), $timezone)->startOfDay()
            : now($timezone)->startOfDay();

        $from = $request->filled('from')
            ? Carbon::parse($request->input('from'), $timezone)->startOfDay()
            : $to->copy()->startOfMonth();

        if ($from->gt($to)) {
            throw ValidationException::withMessages(['from' => 'The start date cannot be after the end date.']);
        }

        if (abs($from->diffInDays($to)) > 366) {
            throw ValidationException::withMessages(['from' => 'Choose a period of at most one year.']);
        }

        return [$school, $from, $to, $from->copy()->utc(), $to->copy()->endOfDay()->utc()];
    }

    /** Stops spreadsheet programs from running a cell as a formula. */
    private function safeCell(mixed $value): mixed
    {
        return is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) ? "'" . $value : $value;
    }
}
