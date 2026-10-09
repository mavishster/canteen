<?php

namespace App\Console\Commands;

use App\Models\Topup;
use App\Services\Payments\PaymentGateways;
use App\Services\TopUpService;
use Illuminate\Console\Command;
use Throwable;

class ReconcileTopups extends Command
{
    protected $signature = 'topups:reconcile';

    protected $description = 'Re-check pending top-ups with the payment gateway (covers missed callbacks) and expire very old ones.';

    public function handle(TopUpService $topUps): int
    {
        $checked = 0;

        Topup::withoutGlobalScopes()
            ->where('status', 'pending')
            ->where('created_at', '<=', now()->subMinutes(2))
            ->orderBy('id')
            ->chunkById(100, function ($chunk) use ($topUps, &$checked) {
                foreach ($chunk as $topup) {
                    try {
                        $gateway = PaymentGateways::get($topup->gateway);
                        $settled = $topUps->settle($topup->gateway_ref, $topup->gateway, $gateway->checkTransaction($topup->gateway_ref));

                        if ($settled->status === 'pending' && $settled->created_at->lt(now()->subDays(3))) {
                            $settled->update(['status' => 'expired']);
                        }

                        $checked++;
                    } catch (Throwable $e) {
                        report($e);   // one broken top-up must not stop the others
                    }
                }
            });

        $this->info("Checked {$checked} pending top-up(s).");

        return self::SUCCESS;
    }
}
