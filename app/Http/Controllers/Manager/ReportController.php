<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\LedgerEntry;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\School;
use App\Models\Topup;
use App\Models\User;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /** What was sold on a day (school time). Refunded sales are left out and listed separately. */
    public function daily(Request $request)
    {
        [$school, $day, $from, $to] = $this->period($request);
        $currency = $school->currency;

        $base = fn () => Sale::withoutGlobalScopes()
            ->where('school_id', $school->id)
            ->whereBetween('created_at', [$from, $to]);

        $summary = [
            'count' => $base()->whereNull('voided_at')->count(),
            'gross' => (int) $base()->whereNull('voided_at')->sum('total'),
            'voided_count' => $base()->whereNotNull('voided_at')->count(),
            'voided_total' => (int) $base()->whereNotNull('voided_at')->sum('total'),
        ];
        $summary['average'] = $summary['count'] > 0 ? intdiv($summary['gross'], $summary['count']) : 0;

        $products = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.school_id', $school->id)
            ->whereNull('sales.voided_at')
            ->whereBetween('sales.created_at', [$from, $to])
            ->groupBy('sale_items.product_id', 'sale_items.name')
            ->selectRaw('sale_items.name as name, sum(sale_items.quantity) as qty, sum(sale_items.line_total) as revenue')
            ->orderByDesc('revenue')
            ->get();

        $cashierRows = $base()->whereNull('voided_at')
            ->selectRaw('cashier_id, count(*) as sales, sum(total) as total')
            ->groupBy('cashier_id')
            ->get();

        $cashierNames = User::whereIn('id', $cashierRows->pluck('cashier_id')->filter())->pluck('name', 'id');

        return view('manager.reports.daily', compact('school', 'day', 'currency', 'summary', 'products', 'cashierRows', 'cashierNames'));
    }

    /** Money movements for a day, for the bursar. Matching against the ABA statement is done with the CSV below. */
    public function reconciliation(Request $request)
    {
        [$school, $day, $from, $to] = $this->period($request);
        $currency = $school->currency;

        $movements = LedgerEntry::withoutGlobalScopes()
            ->where('school_id', $school->id)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('type, count(*) as entries, sum(amount) as total')
            ->groupBy('type')
            ->get()
            ->keyBy(fn ($row) => $row->type->value);

        $paidTopups = Topup::withoutGlobalScopes()
            ->where('school_id', $school->id)
            ->where('status', 'paid')
            ->whereBetween('paid_at', [$from, $to])
            ->selectRaw('pay_currency, count(*) as n, sum(pay_amount) as paid, sum(amount) as credited')
            ->groupBy('pay_currency')
            ->get();

        $otherTopups = Topup::withoutGlobalScopes()
            ->where('school_id', $school->id)
            ->whereIn('status', ['pending', 'review', 'failed', 'expired'])
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        $needReview = Topup::withoutGlobalScopes()
            ->where('school_id', $school->id)
            ->where('status', 'review')
            ->count();

        // Every account's balance must equal the sum of its ledger entries
        $mismatched = DB::table('accounts')
            ->leftJoinSub(
                DB::table('ledger_entries')->selectRaw('account_id, sum(amount) as total')->groupBy('account_id'),
                'l',
                'l.account_id',
                '=',
                'accounts.id'
            )
            ->where('accounts.school_id', $school->id)
            ->whereRaw('accounts.balance <> coalesce(l.total, 0)')
            ->count();

        $heldInWallets = (int) Account::withoutGlobalScopes()->where('school_id', $school->id)->sum('balance');

        return view('manager.reports.reconciliation', compact(
            'school', 'day', 'currency', 'movements', 'paidTopups', 'otherTopups', 'needReview', 'mismatched', 'heldInWallets'
        ));
    }

    public function topupsCsv(Request $request)
    {
        [$school, $day, $from, $to] = $this->period($request);

        $query = Topup::withoutGlobalScopes()
            ->with('student:id,student_code')
            ->where('school_id', $school->id)
            ->where('status', 'paid')
            ->whereBetween('paid_at', [$from, $to])
            ->orderBy('paid_at');

        return response()->streamDownload(function () use ($query, $school) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['paid_at', 'gateway', 'gateway_ref', 'gateway_txn_id', 'student_code', 'pay_currency', 'pay_amount', 'credited_currency', 'credited_amount']);

            $query->chunk(500, function ($rows) use ($out, $school) {
                foreach ($rows as $t) {
                    fputcsv($out, array_map([$this, 'safeCell'], [
                        $t->paid_at->timezone($school->timezone)->format('Y-m-d H:i:s'),
                        $t->gateway,
                        $t->gateway_ref,
                        $t->gateway_txn_id,
                        $t->student->student_code,
                        $t->pay_currency,
                        Money::toMajor($t->pay_amount, $t->pay_currency),
                        $school->currency,
                        Money::toMajor($t->amount, $school->currency),
                    ]));
                }
            });

            fclose($out);
        }, "topups-{$day->toDateString()}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Stops spreadsheet programs from running a cell as a formula. */
    private function safeCell(mixed $value): mixed
    {
        return is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)
            ? "'" . $value
            : $value;
    }

    /** @return array{0: School, 1: Carbon, 2: Carbon, 3: Carbon} school, the day, and its UTC start and end */
    private function period(Request $request): array
    {
        abort_unless($request->user()->school_id, 403, 'Log in as a school user to see reports.');

        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        $school = School::findOrFail($request->user()->school_id);

        $day = $request->filled('date')
            ? Carbon::parse($request->input('date'), $school->timezone)->startOfDay()
            : now($school->timezone)->startOfDay();

        return [$school, $day, $day->copy()->utc(), $day->copy()->endOfDay()->utc()];
    }
}
