<?php

namespace App\Http\Controllers;

use App\Exceptions\RefundException;
use App\Models\Sale;
use App\Models\SaleRefund;
use App\Models\School;
use App\Models\User;
use App\Services\RefundService;
use Carbon\Carbon;
use Illuminate\Http\Request;

/** The cashier's list of sales, where a refund can be requested (or done at once by a manager). */
class SalesLogController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->school_id, 403, 'Log in as a school user to see sales.');

        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        $school = School::findOrFail($user->school_id);
        $isManager = $user->hasAnyRole(['manager', 'admin', 'super-admin']);

        // Cashiers see today only; managers can look at any day
        $day = ($isManager && $request->filled('date'))
            ? Carbon::parse($request->input('date'), $school->timezone)->startOfDay()
            : now($school->timezone)->startOfDay();

        $sales = Sale::with(['student:id,name,student_code', 'items'])
            ->whereBetween('created_at', [$day->copy()->utc(), $day->copy()->endOfDay()->utc()])
            ->latest('id')
            ->limit(200)
            ->get();

        $refunds = SaleRefund::whereIn('sale_id', $sales->pluck('id'))
            ->orderBy('id')
            ->get()
            ->groupBy('sale_id')
            ->map->last();

        $cashiers = User::whereIn('id', $sales->pluck('cashier_id')->filter())->pluck('name', 'id');
        $currency = $school->currency;

        return view('till.sales', compact('sales', 'refunds', 'cashiers', 'school', 'currency', 'isManager', 'day'));
    }

    public function refund(Request $request, Sale $sale, RefundService $refunds)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $user = $request->user();
        $isManager = $user->hasAnyRole(['manager', 'admin', 'super-admin']);

        try {
            $isManager
                ? $refunds->refundNow($sale, $user, $data['reason'])
                : $refunds->request($sale, $user, $data['reason']);
        } catch (RefundException $e) {
            return back()->withErrors(['refund' => $e->getMessage()]);
        }

        return back()->with('status', $isManager
            ? 'Sale refunded. The money and the stock are back.'
            : 'Refund requested. A manager has to approve it.');
    }
}
