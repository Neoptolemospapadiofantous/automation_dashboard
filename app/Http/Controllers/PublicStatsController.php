<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Lead;
use App\Models\Message;
use App\Models\PlatformSetting;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Public, unauthenticated JSON of platform-wide metrics for the marketing
 * site. CORS open to '*' because there is nothing tenant-specific in the
 * payload — every number is either operator-curated (PlatformSetting) or
 * a pure aggregate count.
 *
 * Cached for 5 minutes. PlatformSetting::put busts the cache on write so
 * operator edits land immediately; aggregate counts naturally lag up to
 * the TTL (acceptable — landing-page numbers don't need to tick live).
 *
 * Throttled per-IP at the route level. Anyone scraping us will see a
 * stale-but-valid cached response anyway, so the cost ceiling is bounded.
 */
class PublicStatsController extends Controller
{
    private const CACHE_KEY = 'public_stats';
    private const CACHE_TTL = 300; // 5 minutes

    public function show(): JsonResponse
    {
        $payload = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn () => $this->compute());

        return response()->json($payload)
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'GET, OPTIONS')
            ->header('Cache-Control', 'public, max-age='.self::CACHE_TTL);
    }

    private function compute(): array
    {
        // Editable: hand-managed scarcity/marketing numbers. Operator
        // tweaks them via `php artisan platform:set <key> <value>`.
        $editable = [
            // "Only 47 founder spots left" — classic SaaS scarcity copy.
            // We sell on the landing page, the dashboard doesn't deduct
            // automatically (we want the operator to control the rate).
            'founder_slots_remaining' => PlatformSetting::int('founder_slots_remaining', 100),
            'founder_slots_total' => PlatformSetting::int('founder_slots_total', 100),

            // Next-cohort hook ("Onboarding starts March 15"). Free-form
            // string so it can carry a date, week label, or "Rolling".
            'next_cohort_label' => PlatformSetting::value('next_cohort_label', 'Rolling intake'),

            // Featured proof point — operator picks one outcome to surface
            // ("3.4× pipeline lift at Pendola"). Free-form so it can be
            // anything we want to A/B.
            'featured_proof' => PlatformSetting::value('featured_proof'),
        ];

        // Computed: live aggregate counts. Cheap — each is one indexed
        // count query, cached together for 5 min.
        $computed = [
            // Total active customer accounts. Headline trust signal.
            'teams_count' => Team::count(),

            // Agents currently provisioned and running. Proof of scale.
            'agents_active' => Agent::where('status', Agent::STATUS_ACTIVE)->count(),

            // Total leads the platform has captured + qualified across
            // all tenants. Proof of value (numbers users care about).
            'leads_total' => Lead::count(),
            'leads_qualified' => Lead::where('status', 'qualified')->count(),

            // Total conversational messages handled (user + agent turns
            // both counted, matches what "messages" means in billing).
            'messages_handled' => Message::count(),
        ];

        return [
            ...$editable,
            ...$computed,
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
