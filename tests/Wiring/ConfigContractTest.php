<?php

namespace Tests\Wiring;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Category E (Wiring) — E5 config & environment contract.
 *
 * Pins every config key the runtime and billing layers read, and keeps
 * .env.example honest so a fresh clone + `cp .env.example .env` boots.
 */
class ConfigContractTest extends TestCase
{
    /**
     * Framework-shipped config files reference optional driver settings
     * via env('X') with no fallback (null is a valid "off" value). They
     * are not part of this app's deployment contract, so they are exempt
     * from .env.example parity.
     *
     * TODO: if any of these drivers is ever adopted (e.g. Resend mail,
     * Meilisearch scout), move its vars into .env.example and drop them
     * from this whitelist.
     *
     * @var list<string>
     */
    private const ENV_PARITY_WHITELIST = [
        // broadcasting.php — unused ably driver
        'ABLY_KEY',
        // filesystems.php — optional S3 endpoint overrides
        'AWS_ENDPOINT', 'AWS_URL',
        // cache.php — optional store overrides (db/dynamo/memcached)
        'DB_CACHE_CONNECTION', 'DB_CACHE_LOCK_CONNECTION', 'DB_CACHE_LOCK_TABLE',
        'DYNAMODB_ENDPOINT', 'MEMCACHED_PASSWORD', 'MEMCACHED_PERSISTENT_ID', 'MEMCACHED_USERNAME',
        // queue.php — optional connection overrides
        'DB_QUEUE_CONNECTION', 'SQS_SUFFIX',
        // database.php — optional URL-style / TLS configuration
        'DB_URL', 'MYSQL_ATTR_SSL_CA', 'REDIS_URL', 'REDIS_USERNAME',
        // logging.php — unused papertrail/stderr channels
        'LOG_STDERR_FORMATTER', 'PAPERTRAIL_PORT', 'PAPERTRAIL_URL',
        // mail.php / services.php — unused mailers
        'MAIL_LOG_CHANNEL', 'MAIL_URL', 'POSTMARK_API_KEY', 'POSTMARK_MESSAGE_STREAM_ID', 'RESEND_API_KEY',
        // scout.php — unused meilisearch driver
        'MEILISEARCH_KEY',
        // session.php — optional cookie/store overrides
        'SESSION_CONNECTION', 'SESSION_SECURE_COOKIE', 'SESSION_STORE',
    ];

    public function test_anthropic_api_key_is_part_of_the_config_contract(): void
    {
        // The key must EXIST (empty string allowed — the runtime throws
        // Misconfigured at call time). A missing key means someone deleted
        // the config line and the engine can never be configured.
        $anthropic = config('runtime.llm.anthropic');

        $this->assertIsArray($anthropic);
        $this->assertArrayHasKey('api_key', $anthropic);
    }

    public function test_tiers_map_has_exactly_the_five_tiers(): void
    {
        $tiers = config('runtime.tiers');

        $this->assertIsArray($tiers);
        $this->assertSame(['haiku', 'sonnet', 'opus', 'gpt', 'gemini'], array_keys($tiers));
    }

    public function test_every_tier_declares_provider_model_and_credit_price(): void
    {
        foreach ((array) config('runtime.tiers') as $tier => $definition) {
            $this->assertNotNull($definition['provider'] ?? null, "runtime.tiers.{$tier}.provider missing");
            $this->assertNotNull($definition['model'] ?? null, "runtime.tiers.{$tier}.model missing");
            $this->assertIsInt($definition['credits_per_message'] ?? null, "runtime.tiers.{$tier}.credits_per_message missing");
            $this->assertGreaterThan(0, $definition['credits_per_message'], "runtime.tiers.{$tier} must cost at least 1 credit");
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function requiredConfigKeys(): array
    {
        $keys = [
            // Engine
            'runtime.llm.anthropic.base_url',
            'runtime.llm.anthropic.model_default',
            'runtime.llm.anthropic.max_tokens',
            'runtime.llm.openai.base_url',
            'runtime.llm.openai.model_default',
            'runtime.llm.google.base_url',
            'runtime.llm.google.model_default',
            // Pinned tier the product defaults to
            'runtime.tiers.haiku.model',
            // RAG + embeddings
            'runtime.embeddings.model',
            'runtime.embeddings.dimensions',
            'runtime.rag.chunk_size_tokens',
            'runtime.rag.chunk_overlap_tokens',
            'runtime.rag.retrieval_top_k',
            'runtime.rag.min_similarity',
            // Safety rails
            'runtime.safety.max_tool_calls_per_turn',
            'runtime.safety.max_turns_per_session',
            'runtime.safety.free_greetings_per_day',
            // Session management
            'runtime.session.history_limit',
            'runtime.session.prune_days',
            // Stripe plumbing read by SubscribeController/StripeWebhookController
            'billing.stripe.api_version',
        ];

        return array_combine($keys, array_map(fn (string $key): array => [$key], $keys));
    }

    /**
     * @dataProvider requiredConfigKeys
     */
    public function test_required_config_key_is_non_null(string $key): void
    {
        $this->assertNotNull(config($key), "Missing config: {$key}");
    }

    public function test_billing_price_keys_read_by_plan_and_top_up_pack_exist(): void
    {
        // Plan::stripePriceId() + TopUpPack::stripePriceId() read these.
        // Values may be null until Stripe products exist; the KEYS must.
        $prices = config('billing.stripe_price');
        $this->assertIsArray($prices);

        foreach ([
            'starter', 'starter_annual', 'operator', 'operator_annual',
            'topup_small', 'topup_medium', 'topup_large',
        ] as $key) {
            $this->assertArrayHasKey($key, $prices, "billing.stripe_price.{$key} missing");
        }

        $stripe = config('billing.stripe');
        $this->assertIsArray($stripe);
        foreach (['key', 'secret', 'webhook_secret', 'api_version'] as $key) {
            $this->assertArrayHasKey($key, $stripe, "billing.stripe.{$key} missing");
        }
    }

    public function test_env_example_covers_every_required_env_var_referenced_in_config(): void
    {
        // Vars referenced WITH a fallback are safe to omit from
        // .env.example (the default carries a fresh clone). Vars
        // referenced WITHOUT a fallback silently become null — those must
        // be documented in .env.example (commented-out lines count as
        // documented) or explicitly whitelisted above.
        $referenced = [];
        foreach (File::files(config_path()) as $file) {
            preg_match_all(
                "/env\\(\\s*['\"]([A-Z0-9_]+)['\"]\\s*\\)/",
                $file->getContents(),
                $matches
            );
            foreach ($matches[1] as $var) {
                $referenced[$var] = true;
            }
        }

        $this->assertNotEmpty($referenced, 'config/ env() discovery looks broken');

        $documented = [];
        foreach (file(base_path('.env.example')) ?: [] as $line) {
            if (preg_match('/^#?\s*([A-Z0-9_]+)=/', trim($line), $matches)) {
                $documented[$matches[1]] = true;
            }
        }

        $missing = array_keys(array_diff_key(
            $referenced,
            $documented,
            array_flip(self::ENV_PARITY_WHITELIST)
        ));
        sort($missing);

        $this->assertSame(
            [],
            $missing,
            "env vars referenced in config/ with no fallback and no .env.example line:\n".implode("\n", $missing)
        );
    }
}
