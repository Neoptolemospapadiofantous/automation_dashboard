<?php

namespace App\Http\Controllers;

use App\Events\DashboardTick;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class DashboardTickController extends Controller
{
    /**
     * Fire a single dashboard tick. The broadcast is what makes every
     * connected browser update instantly; the HTTP response is just an ack.
     */
    public function __invoke(): JsonResponse
    {
        $count = Cache::increment('dashboard_tick_count');

        $tick = new DashboardTick(
            count: (int) $count,
            message: 'Live tick from the server',
            at: now()->toIso8601String(),
        );

        broadcast($tick);

        return response()->json([
            'ok' => true,
            'count' => $tick->count,
            'at' => $tick->at,
        ]);
    }
}
