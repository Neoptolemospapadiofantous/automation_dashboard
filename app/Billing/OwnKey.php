<?php

namespace App\Billing;

use App\Models\Agent;
use App\Models\AgentConfigVersion;
use App\Models\Team;
use App\Models\TeamProviderKey;
use App\Runtime\LLM\LlmRouter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Bring-your-own-key: the single place that decides whether a chat turn runs
 * on the customer's provider key instead of the platform's.
 *
 * The trade the feature makes:
 *   - the model bill moves to the customer (their key, their account)
 *   - the turn therefore costs 0 credits
 *   - and because credits no longer bound usage, a monthly MESSAGE cap does
 *     (Plan::monthlyMessageCap)
 *
 * Everything that is still ours stays metered in credits — KB ingestion,
 * automations, embeddings — because those run on our keys regardless of
 * what the customer supplies for chat.
 *
 * Three conditions must all hold, and they are re-checked per turn rather
 * than cached on the team, so a downgrade or a revoked key takes effect on
 * the very next message:
 *   1. the plan allows it        (Operator and above)
 *   2. a usable key exists       (stored AND last probe succeeded)
 *      for the provider THIS agent's tier resolves to
 *   3. the team is under its monthly message cap
 */
class OwnKey
{
    /**
     * The provider an agent's published tier resolves to ('anthropic' etc.).
     */
    public function providerFor(Agent $agent): string
    {
        $tier = AgentConfigVersion::publishedTier($agent->id);

        return (string) config("runtime.tiers.{$tier}.provider", 'anthropic');
    }

    /**
     * The usable key this team has for a provider, or null.
     *
     * Null when the plan does not allow BYOK, so a downgrade disables the
     * feature without anyone having to delete the stored row.
     */
    public function keyFor(Team $team, string $provider): ?TeamProviderKey
    {
        if (! $team->planObject()->allowsOwnKey()) {
            return null;
        }

        $key = TeamProviderKey::where('team_id', $team->id)
            ->where('provider', $provider)
            ->first();

        return $key?->isUsable() ? $key : null;
    }

    /**
     * Does this team hold ANY usable key? Used by the authenticated chat
     * pre-flight, which runs before an agent is resolved. Deliberately
     * coarser than coversAgent() — the precise decision is made later.
     */
    public function teamHasUsableKey(Team $team): bool
    {
        if (! $team->planObject()->allowsOwnKey()) {
            return false;
        }

        return TeamProviderKey::where('team_id', $team->id)
            ->get()
            ->contains(fn (TeamProviderKey $k) => $k->isUsable());
    }

    /**
     * Does this agent's next chat turn run on the customer's key?
     *
     * False when over the cap: past the ceiling a BYOK team falls back to
     * ordinary credit metering rather than being cut off, so the cap limits
     * free usage instead of breaking the widget.
     */
    public function coversAgent(Agent $agent): bool
    {
        $team = $agent->team;
        if (! $team instanceof Team) {
            return false;
        }

        return $this->keyFor($team, $this->providerFor($agent)) !== null
            && $this->withinCap($team);
    }

    /**
     * The tier a turn ACTUALLY runs on, as opposed to the one the customer
     * picked on the Versions page.
     *
     * Premium engines are BYOK-only (config `byok_only`): platform credits
     * never buy Claude or Gemini. When an agent is published on one of them
     * and the team's key cannot carry the turn — plan below Growth, no key,
     * a revoked key, or the monthly cap reached — it degrades to Flowstack
     * Core rather than failing. A revoked key mid-month must never take a
     * customer's widget off the air; it just moves them back to Core.
     *
     * publishedTier() stays "what they chose" so the Versions page keeps
     * showing their selection; this is "what we will run and bill".
     */
    public function effectiveTier(Agent $agent): string
    {
        $tier = AgentConfigVersion::publishedTier($agent->id);

        if (! (bool) config("runtime.tiers.{$tier}.byok_only", false)) {
            return $tier;
        }

        return $this->coversAgent($agent) ? $tier : $this->platformTier();
    }

    /**
     * The cheapest tier platform credits can actually buy — the landing spot
     * when a premium engine has nothing to run on. Never returns a BYOK-only
     * tier, so the fallback cannot itself be unrunnable: the configured
     * default when that is billable, otherwise the first billable tier.
     */
    public function platformTier(): string
    {
        $default = AgentConfigVersion::defaultTier();
        if (! (bool) config("runtime.tiers.{$default}.byok_only", false)) {
            return $default;
        }

        // Prefer one we can actually reach — falling back to a tier whose
        // provider has no key would trade a BYOK dead end for a dead engine.
        foreach ((array) config('runtime.tiers') as $key => $tier) {
            if (($tier['byok_only'] ?? false)) {
                continue;
            }
            if (LlmRouter::providerAvailable((string) ($tier['provider'] ?? 'anthropic'))) {
                return (string) $key;
            }
        }

        return $default;
    }

    /**
     * Credits per message for the tier this agent will actually run on.
     * Non-chat LLM work (KB answers, agent-to-agent) bills at this rate —
     * it runs on the platform key, so BYOK does not zero it.
     */
    public function effectiveCreditsPerMessage(Agent $agent): int
    {
        $tier = $this->effectiveTier($agent);

        return max(1, (int) config("runtime.tiers.{$tier}.credits_per_message", 1));
    }

    /**
     * Credits a chat turn should cost for this agent — 0 when the customer's
     * own key carries it, otherwise the effective tier's price. Debit sites
     * call this instead of reading creditsPerMessage directly, so the
     * zeroing lives in one place.
     */
    public function creditsForChat(Agent $agent): int
    {
        return $this->coversAgent($agent)
            ? 0
            : $this->effectiveCreditsPerMessage($agent);
    }

    /**
     * Probe a key against its provider before storing it.
     *
     * A key is only ever saved after this succeeds, so the FIRST customer
     * message is never the test. Returns null on success, or a short,
     * user-facing reason on failure.
     *
     * The probe asks for a single token from the cheapest path — enough to
     * prove auth and model access without a meaningful charge on their
     * account.
     */
    public function verify(string $provider, string $apiKey, ?string $model = null): ?string
    {
        try {
            $response = match ($provider) {
                'anthropic' => Http::timeout(15)
                    ->withHeaders([
                        'x-api-key' => $apiKey,
                        'anthropic-version' => (string) config('runtime.llm.anthropic.version', '2023-06-01'),
                    ])
                    ->post(rtrim((string) config('runtime.llm.anthropic.base_url', 'https://api.anthropic.com'), '/').'/v1/messages', [
                        'model' => $model ?: (string) config('runtime.llm.anthropic.model_default'),
                        'max_tokens' => 1,
                        'messages' => [['role' => 'user', 'content' => 'hi']],
                    ]),
                'openai' => Http::timeout(15)
                    ->withToken($apiKey)
                    ->get(rtrim((string) config('runtime.llm.openai.base_url', 'https://api.openai.com'), '/').'/v1/models'),
                'google' => Http::timeout(15)
                    ->withHeaders(['x-goog-api-key' => $apiKey])
                    ->get(rtrim((string) config('runtime.llm.google.base_url', 'https://generativelanguage.googleapis.com'), '/').'/v1beta/models'),
                default => null,
            };
        } catch (\Throwable $e) {
            return 'Could not reach '.$provider.' ('.class_basename($e).').';
        }

        if ($response === null) {
            return "Provider '{$provider}' does not support customer-supplied keys.";
        }
        if ($response->successful()) {
            return null;
        }

        // Surface the provider's own wording where it is useful — a 401 means
        // a bad key, a 403/404 usually means the key lacks that model.
        $reason = (string) ($response->json('error.message') ?? $response->json('message') ?? '');

        return match ($response->status()) {
            401 => 'That key was rejected as invalid.',
            403 => 'That key is valid but not permitted for this model.',
            404 => 'That key cannot access the model this agent uses.',
            429 => 'That key is rate limited right now — try again shortly.',
            default => 'Provider returned '.$response->status().($reason !== '' ? ': '.mb_substr($reason, 0, 120) : '.'),
        };
    }

    /**
     * Messages used in the current BYOK window, resetting the window when it
     * has aged past a month. Reading performs the reset so no scheduled job
     * is needed — the counter is only ever consulted on a live turn.
     */
    public function messagesUsed(Team $team): int
    {
        $start = $team->byok_period_start;

        if ($start === null || Carbon::parse($start)->lt(now()->subMonth())) {
            return 0;
        }

        return (int) $team->byok_messages_used;
    }

    public function withinCap(Team $team): bool
    {
        return $this->messagesUsed($team) < $team->planObject()->monthlyMessageCap();
    }

    /**
     * Count one BYOK turn. Atomic, and rolls the window when it has expired.
     *
     * Best-effort by design: a counter write must never fail a turn the
     * customer has already paid their provider for.
     */
    public function recordMessage(Team $team): void
    {
        try {
            DB::transaction(function () use ($team) {
                $fresh = Team::lockForUpdate()->find($team->id);
                if (! $fresh instanceof Team) {
                    return;
                }

                $start = $fresh->byok_period_start;
                $expired = $start === null || Carbon::parse($start)->lt(now()->subMonth());

                $fresh->forceFill([
                    'byok_messages_used' => $expired ? 1 : $fresh->byok_messages_used + 1,
                    'byok_period_start' => $expired ? now() : $start,
                ])->save();
            });
        } catch (\Throwable) {
            // Never break a turn over a usage counter.
        }
    }
}
