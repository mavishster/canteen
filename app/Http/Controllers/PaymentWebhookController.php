<?php

namespace App\Http\Controllers;

use App\Services\Payments\InvalidCallbackException;
use App\Services\Payments\PaymentGateways;
use App\Services\TopUpService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use InvalidArgumentException;
use LogicException;

class PaymentWebhookController extends Controller
{
    public function handle(Request $request, string $gateway, TopUpService $topUps)
    {
        try {
            $driver = PaymentGateways::get($gateway);
        } catch (InvalidArgumentException) {
            return response()->json(['ok' => false], 404);
        }

        try {
            $topUps->handleCallback($driver, $request->all(), $request->headers->all());
        } catch (InvalidCallbackException) {
            return response()->json(['ok' => false], 400);
        } catch (ModelNotFoundException) {
            return response()->json(['ok' => false], 404);
        } catch (LogicException) {
            return response()->json(['ok' => false], 501);   // gateway driver not finished yet
        }

        // 200 whether the payment is paid, pending or failed: the callback was received and processed
        return response()->json(['ok' => true]);
    }
}
