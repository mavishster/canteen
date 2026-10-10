<?php

namespace App\Http\Controllers\ParentApi;

use App\Exceptions\TopUpException;
use App\Models\Topup;
use App\Services\Payments\PaymentGateways;
use App\Services\TopUpService;
use App\Support\Money;
use Illuminate\Http\Request;
use LogicException;

class TopUpController extends ParentBaseController
{
    /** Starts a payment and returns the page to send the parent to. Nothing is credited until the bank confirms. */
    public function start(Request $request, int $student, TopUpService $topUps)
    {
        $child = $this->child($request, $student);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:100000000'],
            'currency' => ['required', 'in:USD,KHR'],
        ]);

        try {
            $topup = $topUps->start(
                $child,
                Money::toMinor($data['amount'], $data['currency']),
                $data['currency'],
                PaymentGateways::default(),
                $request->user()->id,
            );
        } catch (TopUpException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 422);
        } catch (LogicException) {
            // The gateway driver is not ready (or is switched off in this environment)
            return response()->json(['message' => 'Online payments are not available yet.'], 503);
        }

        return response()->json($this->present($topup, $request), 201);
    }

    /** The app asks this after the parent returns from the bank's page. */
    public function show(Request $request, string $ref)
    {
        $topup = Topup::where('gateway_ref', $ref)
            ->whereIn('student_id', $this->linkedIds($request))
            ->firstOrFail();

        return response()->json($this->present($topup, $request));
    }

    /** @return array<string, mixed> */
    private function present(Topup $topup, Request $request): array
    {
        $currency = $this->school($request)->currency;

        return [
            'ref' => $topup->gateway_ref,
            'status' => $topup->status,
            'checkout_url' => $topup->checkout_url,
            'pay_currency' => $topup->pay_currency,
            'pay_amount' => $topup->pay_amount,
            'credit_amount' => $topup->amount,
            'credit_formatted' => Money::format($topup->amount, $currency),
        ];
    }
}
