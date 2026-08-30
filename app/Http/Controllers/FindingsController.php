<?php

namespace App\Http\Controllers;

use App\Support\Findings\FindingsStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * §3.1 findings tree, served from the database for the grid to mirror into
 * its local data/agents/ tree. Bearer FINDINGS_READ_TOKEN; 503 while unset
 * so a missing key reads as "not configured", never as "no findings".
 */
class FindingsController extends Controller
{
    public function __invoke(Request $request, FindingsStore $store): JsonResponse
    {
        $token = (string) config('services.findings.token', '');
        if ($token === '') {
            return response()->json(['error' => 'Findings endpoint is not configured.'], 503);
        }
        if (! hash_equals($token, (string) $request->bearerToken())) {
            return response()->json(['error' => 'Unauthorized.'], 401);
        }

        return response()->json([
            'ts' => now()->toIso8601ZuluString(),
            'collectors' => $store->latest(),
        ]);
    }
}
