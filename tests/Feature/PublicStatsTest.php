<?php

namespace Tests\Feature;

use App\Http\Controllers\PublicStatsController;
use App\Models\Agent;
use App\Models\Lead;
use App\Models\Message;
use App\Models\PlatformSetting;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Public platform-stats endpoint for the marketing site. The shape contract
 * is load-bearing — landing-site code reads these keys verbatim — so changes
 * here should be paired with a landing-side bump.
 */
class PublicStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_endpoint_returns_default_shape_when_db_empty(): void
    {
        $this->getJson(route('public.stats'))
            ->assertOk()
            ->assertJsonStructure([
                'founder_slots_remaining',
                'founder_slots_total',
                'next_cohort_label',
                'next_cohort_open_at',
                'featured_proof',
                'teams_count',
                'agents_active',
                'leads_total',
                'leads_qualified',
                'messages_handled',
                'generated_at',
            ])
            ->assertJson([
                'founder_slots_remaining' => 100,
                'founder_slots_total' => 100,
                'next_cohort_label' => 'Rolling intake',
                'next_cohort_open_at' => null,
                'featured_proof' => null,
                'teams_count' => 0,
                'agents_active' => 0,
                'leads_total' => 0,
                'leads_qualified' => 0,
                'messages_handled' => 0,
                // Every count below the 10-bucket → display.* is null so
                // the landing site can hide the field instead of saying
                // "0 agents running" which screams empty platform.
                'display' => [
                    'teams_count' => null,
                    'agents_active' => null,
                    'leads_total' => null,
                    'leads_qualified' => null,
                    'messages_handled' => null,
                ],
            ]);
    }

    public function test_endpoint_reflects_platform_settings_and_aggregate_counts(): void
    {
        PlatformSetting::put('founder_slots_remaining', 47);
        PlatformSetting::put('next_cohort_label', 'Starts March 15');
        PlatformSetting::put('next_cohort_open_at', '2026-07-15');
        PlatformSetting::put('featured_proof', '3.4× pipeline at Pendola');

        // Seed three teams; the third also has an active agent + 2 leads
        // (one qualified) + 5 messages so every counter is non-zero.
        User::factory()->withPersonalTeam()->count(3)->create();
        $team = Team::first();
        $agent = Agent::factory()->for($team)->create(['status' => Agent::STATUS_ACTIVE]);
        Lead::factory()->for($team)->for($agent)->create(['status' => 'qualified']);
        Lead::factory()->for($team)->for($agent)->create(['status' => 'new']);
        Message::factory()->count(5)->for($agent->conversations()->create([
            'team_id' => $team->id,
            'visitor_id' => 'web-stats-test',
        ]))->create();

        $this->getJson(route('public.stats'))
            ->assertOk()
            ->assertJson([
                'founder_slots_remaining' => 47,
                'next_cohort_label' => 'Starts March 15',
                'next_cohort_open_at' => '2026-07-15',
                'featured_proof' => '3.4× pipeline at Pendola',
                'teams_count' => 3,
                'agents_active' => 1,
                'leads_total' => 2,
                'leads_qualified' => 1,
                'messages_handled' => 5,
                // All counts still under 10 → all bucketed labels null.
                'display' => [
                    'teams_count' => null,
                    'agents_active' => null,
                    'leads_total' => null,
                    'leads_qualified' => null,
                    'messages_handled' => null,
                ],
            ]);
    }

    public function test_display_buckets_snap_to_marketing_tiers(): void
    {
        // The bucket ladder snaps a raw count DOWN to a marketing tier
        // (10, 25, 50, 100, 250, 500, 1k, 2.5k, …) so 12 → "10+", 1234 → "1k+".
        // This is a pure function of the count — assert it directly across the
        // whole ladder rather than materializing up to 1.2M real rows just to
        // drive COUNT(*) (which on MariaDB is minutes of row-by-row inserts).
        $bucket = new \ReflectionMethod(PublicStatsController::class, 'bucket');
        $bucket->setAccessible(true);
        $controller = new PublicStatsController;

        $ladder = [
            0 => null, 9 => null, 10 => '10+', 24 => '10+', 25 => '25+',
            49 => '25+', 50 => '50+', 99 => '50+', 100 => '100+', 249 => '100+',
            250 => '250+', 1234 => '1k+', 2499 => '1k+', 2500 => '2.5k+',
            10_000 => '10k+', 1_234_567 => '1M+',
        ];
        foreach ($ladder as $n => $expected) {
            $this->assertSame($expected, $bucket->invoke($controller, $n), "bucket($n)");
        }

        // End-to-end wiring on the real DB: a genuine COUNT(*) flows through to
        // display.* via the endpoint. 250 real rows is enough to prove the path.
        \Schema::withoutForeignKeyConstraints(fn () => \DB::table('teams')->delete());
        \DB::table('teams')->insert(array_map(fn ($i) => [
            'user_id' => 1,
            'name' => "t-$i",
            'personal_team' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], range(0, 249)));
        Cache::flush();

        $this->getJson(route('public.stats'))
            ->assertOk()
            ->assertJsonPath('display.teams_count', '250+');
    }

    public function test_endpoint_sets_cors_headers_for_landing_site(): void
    {
        $this->getJson(route('public.stats'))
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeader('Cache-Control', 'max-age=300, public');
    }

    public function test_writing_a_setting_busts_the_cache(): void
    {
        // First call seeds cache with default 100.
        $this->getJson(route('public.stats'))
            ->assertOk()
            ->assertJsonPath('founder_slots_remaining', 100);

        // Without cache busting this would still return 100 for 5 min.
        // PlatformSetting::put forgets the cache key — verify the next
        // call reflects the new value immediately.
        PlatformSetting::put('founder_slots_remaining', 5);

        $this->getJson(route('public.stats'))
            ->assertOk()
            ->assertJsonPath('founder_slots_remaining', 5);
    }

    public function test_response_is_cached_between_requests(): void
    {
        // First call populates the cache. Second call comes back from cache
        // — verify by mutating the underlying count between the two calls
        // and confirming the cached value is what's returned (proves we
        // didn't re-query).
        $this->getJson(route('public.stats'))
            ->assertOk()
            ->assertJsonPath('teams_count', 0);

        User::factory()->withPersonalTeam()->count(5)->create();

        // Second call — cache still holds 0, not 5. (PlatformSetting::put
        // would have busted, but raw model writes don't.)
        $this->getJson(route('public.stats'))
            ->assertOk()
            ->assertJsonPath('teams_count', 0);
    }
}
