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
 *
 * The response includes a `display` sub-object with bucketed strings for
 * each aggregate count (e.g. teams_count=2 → display.teams_count=null,
 * teams_count=147 → display.teams_count="100+"). The landing site reads
 * `display.*` so low real counts during early growth don't become public
 * anti-social-proof. Raw counts stay in the response for callers that
 * want exact numbers.
 */
class PublicStatsController extends Controller
{
    private const CACHE_KEY = 'public_stats';
    private const CACHE_TTL = 300; // 5 minutes

    /**
     * Bucket boundaries — lowest tier shown is 10+. Anything below that
     * returns null from bucket() so the landing site can skip rendering
     * the field entirely (better than "<10" which screams small).
     */
    private const BUCKETS = [10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000, 25000, 50000, 100000, 250000, 500000, 1000000];

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
            //
            // SECURITY: landing site MUST render this as text (React's
            // default {expr}), never via dangerouslySetInnerHTML — the
            // column is free-form so an operator typo could otherwise
            // become stored XSS.
            'featured_proof' => PlatformSetting::value('featured_proof'),
        ];

        // Computed: live aggregate counts. Cheap — each is one indexed
        // count query, cached together for 5 min.
        $counts = [
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

        // Bucketed display labels — null when below the 10-bucket so the
        // landing site can hide the field instead of broadcasting a small
        // real number.
        $display = [];
        foreach ($counts as $key => $n) {
            $display[$key] = $this->bucket($n);
        }

        return [
            ...$editable,
            ...$counts,
            'display' => $display,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Snap a count down to the nearest marketing bucket and label it with
     * a "+" suffix. Returns null below the lowest bucket so callers can
     * hide the field rather than show an embarrassingly small number.
     *
     *   2     → null
     *   12    → "10+"
     *   147   → "100+"
     *   1234  → "1k+"
     *   12000 → "10k+"
     */
    private function bucket(int $n): ?string
    {
        if ($n < self::BUCKETS[0]) {
            return null;
        }

        $tier = self::BUCKETS[0];
        foreach (self::BUCKETS as $t) {
            if ($n >= $t) {
                $tier = $t;
            } else {
                break;
            }
        }

        return $this->formatBucket($tier).'+';
    }

    private function formatBucket(int $n): string
    {
        if ($n >= 1_000_000) {
            $m = $n / 1_000_000;
            return ($m == (int) $m ? (string) (int) $m : (string) $m).'M';
        }
        if ($n >= 1000) {
            $k = $n / 1000;
            return ($k == (int) $k ? (string) (int) $k : (string) $k).'k';
        }
        return (string) $n;
    }
}
