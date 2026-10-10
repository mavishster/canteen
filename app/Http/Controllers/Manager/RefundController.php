<?php

namespace App\Http\Controllers\Manager;

use App\Exceptions\RefundException;
use App\Http\Controllers\Controller;
use App\Models\SaleRefund;
use App\Models\School;
use App\Models\User;
use App\Services\RefundService;
use Illuminate\Http\Request;

class RefundController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->school_id, 403, 'Log in as a school user to manage refunds.');

        $school = School::findOrFail($request->user()->school_id);

        $pending = SaleRefund::with(['sale.items', 'sale.student:id,name,student_code'])
            ->where('status', 'pending')
            ->orderBy('id')
            ->get();

        $history = SaleRefund::with(['sale.student:id,name,student_code'])
            ->where('status', '!=', 'pending')
            ->latest('id')
            ->limit(50)
            ->get();

        $names = User::whereIn('id', $pending->pluck('requested_by')
            ->merge($history->pluck('requested_by'))
            ->merge($history->pluck('decided_by'))
            ->filter()->unique())
            ->pluck('name', 'id');

        $currency = $school->currency;

        return view('manager.refunds.index', compact('pending', 'history', 'names', 'school', 'currency'));
    }

    public function approve(Request $request, SaleRefund $refund, RefundService $refunds)
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        try {
            $refunds->approve($refund, $request->user(), $data['note'] ?? null);
        } catch (RefundException $e) {
            return back()->withErrors(['refund' => $e->getMessage()]);
        }

        return back()->with('status', 'Refund approved. The money and the stock are back.');
    }

    public function reject(Request $request, SaleRefund $refund, RefundService $refunds)
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        try {
            $refunds->reject($refund, $request->user(), $data['note'] ?? null);
        } catch (RefundException $e) {
            return back()->withErrors(['refund' => $e->getMessage()]);
        }

        return back()->with('status', 'Refund request rejected.');
    }
}
