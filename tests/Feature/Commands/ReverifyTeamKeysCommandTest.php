<?php

namespace Tests\Feature\Commands;

use App\Billing\Plan;
use App\Models\TeamProviderKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReverifyTeamKeysCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_reprobes_every_stored_key_and_writes_the_result_like_the_reverify_button(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill(['plan' => Plan::Pro->value])->save();

        $verifiedAt = now()->subDays(3)->startOfSecond();
        $good = TeamProviderKey::create([
            'team_id' => $team->id,
            'provider' => 'openai',
            'api_key' => 'sk-good-key',
            'key_hint' => '…-key',
            'last_verified_at' => $verifiedAt,
        ]);
        $revoked = TeamProviderKey::create([
            'team_id' => $team->id,
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-revoked',
            'key_hint' => '…oked',
            'last_verified_at' => $verifiedAt,
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'api.anthropic.com')) {
                return Http::response(['error' => ['message' => 'invalid x-api-key']], 401);
            }

            return Http::response(['data' => []], 200);
        });

        $this->artisan('providers:reverify-team-keys')
            ->expectsOutputToContain('Re-verified 1 key(s) ok, 1 failing.')
            ->assertSuccessful();

        $good->refresh();
        $this->assertNull($good->last_error);
        $this->assertTrue($good->last_verified_at->greaterThan($verifiedAt));
        $this->assertTrue($good->isUsable());

        $revoked->refresh();
        $this->assertSame('That key was rejected as invalid.', $revoked->last_error);
        $this->assertTrue($revoked->last_verified_at->equalTo($verifiedAt), 'a failed probe must not advance last_verified_at');
        $this->assertFalse($revoked->isUsable());

        // Only the customer keys were probed — never the platform providers.
        Http::assertSentCount(2);
    }

    public function test_runs_clean_with_no_keys_stored(): void
    {
        Http::fake();

        $this->artisan('providers:reverify-team-keys')
            ->expectsOutputToContain('Re-verified 0 key(s) ok, 0 failing.')
            ->assertSuccessful();

        Http::assertNothingSent();
    }
}
