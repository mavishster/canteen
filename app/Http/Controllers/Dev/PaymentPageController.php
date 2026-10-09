<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Models\Topup;
use App\Services\Payments\FakeGateway;
use App\Services\TopUpService;

/** The pretend bank page for the fake gateway. Not available in production. */
class PaymentPageController extends Controller
{
    public function show(string $ref)
    {
        abort_if(app()->isProduction(), 404);

        $topup = Topup::with(['student:id,name,student_code', 'school:id,currency'])
            ->where('gateway_ref', $ref)
            ->where('gateway', 'fake')
            ->firstOrFail();

        return view('dev.pay', compact('topup'));
    }

    public function complete(string $ref, string $outcome, FakeGateway $fake, TopUpService $topUps)
    {
        abort_if(app()->isProduction(), 404);
        abort_unless(in_array($outcome, ['paid', 'failed'], true), 404);

        $topup = Topup::where('gateway_ref', $ref)->where('gateway', 'fake')->firstOrFail();

        // The bank records what happened, then calls us exactly as a real gateway would
        $fake->simulate($topup, $outcome);
        $result = $topUps->handleCallback($fake, ['ref' => $ref, 'sig' => $fake->signature($ref)]);

        return redirect()->route('admin.topups')
            ->with('status', "Top-up {$result->gateway_ref}: {$result->status}.");
    }
}
