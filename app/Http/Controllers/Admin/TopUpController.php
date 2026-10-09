<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\TopUpException;
use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Topup;
use App\Services\Payments\PaymentGateways;
use App\Services\TopUpService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;

class TopUpController extends Controller
{
    public function index(Request $request)
    {
        Paginator::useBootstrapFive();

        $topups = Topup::with(['student:id,name,student_code', 'school:id,currency,timezone'])
            ->latest('id')
            ->paginate(25);

        $students = (! app()->isProduction() && $request->user()->school_id)
            ? Student::orderBy('name')->limit(500)->get(['id', 'name', 'student_code'])
            : collect();

        return view('admin.topups.index', compact('topups', 'students'));
    }

    /** Development only: starts a top-up on the fake gateway so the whole flow can be tried. */
    public function store(Request $request, TopUpService $topUps)
    {
        abort_if(app()->isProduction(), 404);

        $data = $request->validate([
            'student_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:100000000'],
            'currency' => ['required', 'in:USD,KHR'],
        ]);

        $student = Student::findOrFail($data['student_id']);

        try {
            $topup = $topUps->start(
                $student,
                Money::toMinor($data['amount'], $data['currency']),
                $data['currency'],
                PaymentGateways::default(),
                $request->user()->id,
            );
        } catch (TopUpException $e) {
            return back()->withErrors(['amount' => $e->getMessage()])->withInput();
        }

        return redirect()->away($topup->checkout_url);
    }
}
