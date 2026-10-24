<?php

namespace App\Http\Controllers;

use App\Support\Ops\OperationsStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/** For an uptime monitor. Off until HEALTH_TOKEN is set; then it needs that token in the X-Health-Token header. */
class HealthController extends Controller
{
    public function __invoke(Request $request)
    {
        $token = (string) config('ops.health_token');

        abort_if($token === '', 404);
        abort_unless(hash_equals($token, (string) $request->header('X-Health-Token', '')), 403);

        try {
            DB::select('select 1');
            $database = true;
        } catch (Throwable) {
            $database = false;
        }

        $checks = OperationsStatus::report(config('app.env') === 'production');
        $levels = collect($checks)->pluck('level');

        $status = (! $database || $levels->contains('FAIL')) ? 'fail' : ($levels->contains('WARN') ? 'warn' : 'ok');

        return response()->json([
            'status' => $status,
            'database' => $database ? 'ok' : 'down',
            'checks' => $checks,
        ], $status === 'fail' ? 503 : 200);
    }
}
