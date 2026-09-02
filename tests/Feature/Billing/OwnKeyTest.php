<?php

namespace Tests\Feature\Billing;

use App\Billing\OwnKey;
use App\Billing\Plan;
use App\Models\Agent;
use App\Models\AgentConfigVersion;
use App\Models\Team;
use App\Models\TeamProviderKey;
use App\Models\User;
use App\Runtime\LLM\AnthropicClient;
use App\Runtime\LLM\BridgeClient;
use App\Runtime\LLM\LlmRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bring-your-own-key: a team on Operator supplies its own provider key, chat
 * turns run on it at 0 credits, and a monthly message cap replaces the credit
 * balance as the ceiling.
 *
 * The cases that matter are the ones where getting it wrong costs money or
 * leaks a secret: a downgrade must stop BYOK, an unverified key must not be
 * used, and the customer's key must never reach the bridge (which is OUR
 * subscription auth).
 */
class OwnKeyTest extends TestCase
{
    use RefreshDatabase;

    private function team(Plan $plan = Plan::Pro): Team
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $team->forceFill(['plan' => $plan->value])->save();

        return $team->fresh();
    }

    /**
     * Put the agent on a premium engine — those are the byok_only tiers a
     * customer key exists to run. An unpublished agent sits on Flowstack
     * Core, which the platform serves and bills.
     */
    private function onPremium(Agent $agent, string $tier = 'haiku'): Agent
    {
        AgentConfigVersion::create([
            'agent_id' => $agent->id,
            'version' => 1,
            'status' => 'published',
            'config' => ['instructions' => '', 'greeting' => '', 'model_tier' => $tier],
            'published_at' => now(),
        ]);

        return $agent->fresh();
    }

    private function key(Team $team, string $provider = 'anthropic', bool $verified = true): TeamProviderKey
    {
        return TeamProviderKey::create([
            'team_id' => $team->id,
            'provider' => $provider,
            'api_key' => 'sk-ant-test-'.str_repeat('x', 20),
            'key_hint' => '…xxxx',
            'last_verified_at' => $verified ? now() : null,
            'last_error' => null,
        ]);
    }

    public function test_byok_is_sold_above_starter_and_not_below(): void
    {
        // Premium engines are BYOK-only, so this gate also decides who can
        // run anything other than Flowstack Core.
        $this->assertTrue(Plan::Growth->allowsOwnKey(), 'Growth is the first rung that sells BYOK');
        $this->assertTrue(Plan::Pro->allowsOwnKey());
        $this->assertTrue(Plan::Business->allowsOwnKey());
        foreach ([Plan::Free, Plan::Starter] as $plan) {
            $this->assertFalse($plan->allowsOwnKey(), $plan->value.' must not allow BYOK');
        }
    }

    public function test_key_is_encrypted_at_rest_and_hidden_from_serialization(): void
    {
        $team = $this->team();
        $key = $this->key($team);

        $raw = \DB::table('team_provider_keys')->where('id', $key->id)->value('api_key');
        $this->assertNotSame($key->api_key, $raw, 'the column must hold ciphertext, not the key');
        $this->assertStringNotContainsString('sk-ant-test', (string) $raw);

        $this->assertArrayNotHasKey('api_key', $key->fresh()->toArray(), 'the key must never serialize');
    }

    public function test_a_verified_key_on_operator_zeroes_the_chat_credits(): void
    {
        $team = $this->team();
        $agent = $this->onPremium(Agent::factory()->for($team)->create());
        $this->key($team);

        $ownKey = app(OwnKey::class);
        $this->assertTrue($ownKey->coversAgent($agent->fresh()));
        $this->assertSame(0, $ownKey->creditsForChat($agent->fresh()));
    }

    public function test_an_unverified_key_is_never_used(): void
    {
        $team = $this->team();
        $agent = Agent::factory()->for($team)->create();
        $this->key($team, verified: false);

        $ownKey = app(OwnKey::class);
        $this->assertFalse($ownKey->coversAgent($agent->fresh()));
        $this->assertGreaterThan(0, $ownKey->creditsForChat($agent->fresh()),
            'an unverified key must fall back to normal credit metering');
    }

    public function test_a_key_whose_last_probe_failed_is_never_used(): void
    {
        $team = $this->team();
        $agent = Agent::factory()->for($team)->create();
        $this->key($team)->forceFill(['last_error' => '401 invalid_api_key'])->save();

        $this->assertFalse(app(OwnKey::class)->coversAgent($agent->fresh()));
    }

    public function test_downgrading_below_growth_stops_byok_without_deleting_the_key(): void
    {
        $team = $this->team();
        $agent = Agent::factory()->for($team)->create();
        $this->key($team);

        $team->forceFill(['plan' => Plan::Starter->value])->save();

        $this->assertFalse(app(OwnKey::class)->coversAgent($agent->fresh()->refresh()));
        // the key row survives a downgrade — BYOK is disabled by the plan gate,
        // not by deleting the customer's credential
        $this->assertDatabaseCount('team_provider_keys', 1);
    }

    public function test_a_key_for_another_provider_does_not_cover_this_agent(): void
    {
        $team = $this->team();
        $agent = $this->onPremium(Agent::factory()->for($team)->create());
        $this->key($team, provider: 'openai');

        // This agent's tier resolves to anthropic, so an OpenAI key must not
        // be handed to an Anthropic call.
        $ownKey = app(OwnKey::class);
        $this->assertSame('anthropic', $ownKey->providerFor($agent->fresh()));
        $this->assertFalse($ownKey->coversAgent($agent->fresh()));
    }

    public function test_the_message_cap_replaces_the_credit_ceiling(): void
    {
        $team = $this->team();
        $agent = Agent::factory()->for($team)->create();
        $this->key($team);
        $ownKey = app(OwnKey::class);

        $team->forceFill([
            'byok_messages_used' => Plan::Pro->monthlyMessageCap(),
            'byok_period_start' => now(),
        ])->save();

        $this->assertFalse($ownKey->withinCap($team->fresh()));
        $this->assertFalse($ownKey->coversAgent($agent->fresh()->refresh()),
            'past the cap a BYOK team falls back to credits rather than being cut off');
        $this->assertGreaterThan(0, $ownKey->creditsForChat($agent->fresh()->refresh()));
    }

    public function test_the_counter_window_rolls_after_a_month(): void
    {
        $team = $this->team();
        $ownKey = app(OwnKey::class);

        $team->forceFill([
            'byok_messages_used' => 999,
            'byok_period_start' => now()->subMonths(2),
        ])->save();

        $this->assertSame(0, $ownKey->messagesUsed($team->fresh()), 'a stale window reads as empty');

        $ownKey->recordMessage($team->fresh());
        $this->assertSame(1, (int) $team->fresh()->byok_messages_used, 'and resets to 1 on the next turn');
    }

    public function test_recording_increments_within_an_open_window(): void
    {
        $team = $this->team();
        $team->forceFill(['byok_messages_used' => 5, 'byok_period_start' => now()->subDays(3)])->save();

        app(OwnKey::class)->recordMessage($team->fresh());

        $this->assertSame(6, (int) $team->fresh()->byok_messages_used);
    }

    public function test_a_customer_key_bypasses_the_bridge(): void
    {
        // The bridge is OUR subscription auth. Routing a customer's traffic to
        // it would bill us and run their chat on a personal subscription — so
        // a supplied key must always produce a direct client.
        config(['runtime.llm.bridge.enabled' => true, 'runtime.llm.bridge.url' => 'http://localhost:8765']);

        $router = app(LlmRouter::class);

        $this->assertInstanceOf(BridgeClient::class, $router->clientFor('anthropic'),
            'without a customer key the bridge still wins');
        $this->assertInstanceOf(AnthropicClient::class, $router->clientFor('anthropic', 'sk-ant-customer'),
            'with a customer key the bridge must be bypassed');
    }

    public function test_the_injected_key_does_not_leak_into_the_shared_singleton(): void
    {
        $shared = app(AnthropicClient::class);
        $scoped = $shared->withApiKey('sk-ant-customer');

        $this->assertNotSame($shared, $scoped, 'withApiKey must clone, never mutate in place');

        // Read the override directly: the point of cloning is that one tenant's
        // key can never bleed into the instance another request reuses.
        $read = function (AnthropicClient $c): ?string {
            $prop = new \ReflectionProperty(AnthropicClient::class, 'apiKeyOverride');

            return $prop->getValue($c);
        };
        $this->assertNull($read($shared), 'the original instance must stay key-less');
        $this->assertSame('sk-ant-customer', $read($scoped));
    }
}
