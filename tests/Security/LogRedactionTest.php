<?php

namespace Tests\Security;

use App\Models\Agent;
use App\Runtime\Contracts\Tool;
use App\Runtime\LLM\ToolCall;
use App\Runtime\Models\RuntimeSession;
use App\Runtime\Session\ConversationContext;
use App\Runtime\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * Category F — log redaction.
 *
 * The runtime's ops logging must never include raw visitor message
 * content (PII: names, emails, whatever the visitor typed). ToolRegistry
 * logs 'Runtime tool failed' with tool/agent_id/error only — this test
 * pins that contract with a sentinel userMessage.
 *
 * Also pins that .env secrets can't reach git: .gitignore must cover
 * .env and its variants while keeping .env.example committable.
 */
class LogRedactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_tool_failure_log_never_contains_the_visitor_message(): void
    {
        $sentinel = 'SENTINEL-raw-visitor-message-9f31c';

        $agent = Agent::factory()->create();
        $session = RuntimeSession::create([
            'agent_id' => $agent->id,
            'visitor_id' => 'embed-test-visitor',
            'flow_state' => 'conversing',
        ]);
        $context = new ConversationContext($agent, $session, $sentinel);

        $registry = new ToolRegistry;
        $registry->register($this->explodingTool());

        Log::spy();

        $result = $registry->dispatch(
            new ToolCall('toolu_01', 'exploding_tool', ['query' => 'anything']),
            $context,
        );

        // The failure is contained (is_error tool_result), not thrown.
        $this->assertTrue($result['is_error']);

        // The failure WAS logged for ops…
        $logged = [];
        Log::shouldHaveReceived('warning')->withArgs(function (...$args) use (&$logged) {
            $logged[] = $args;

            return true;
        });
        $this->assertNotEmpty($logged);

        [$message, $logContext] = $logged[0];
        $this->assertSame('Runtime tool failed', $message);
        $this->assertSame(['tool', 'agent_id', 'error'], array_keys($logContext));

        // …but the visitor's raw message never reached any log call.
        $this->assertStringNotContainsString(
            $sentinel,
            (string) json_encode($logged),
            'Raw visitor message content leaked into the runtime failure log.',
        );
    }

    public function test_gitignore_keeps_env_secrets_out_of_git(): void
    {
        $lines = array_map(
            'trim',
            explode("\n", (string) file_get_contents(base_path('.gitignore'))),
        );

        // The live secrets file must be ignored.
        $this->assertContains('.env', $lines);

        // Env variants must be ignored too — either via a wildcard or by
        // covering the variants that exist in this repo's workflows.
        $coversVariants = in_array('.env.*', $lines, true)
            || (in_array('.env.backup', $lines, true) && in_array('.env.production', $lines, true));
        $this->assertTrue($coversVariants, '.env variants (.env.backup / .env.production / .env.*) must be gitignored.');

        // The template must stay committable: never ignored outright, and
        // if a wildcard is ever introduced it needs a negation alongside.
        $this->assertNotContains('.env.example', $lines);
        if (in_array('.env.*', $lines, true)) {
            $this->assertContains('!.env.example', $lines);
        }
    }

    private function explodingTool(): Tool
    {
        return new class implements Tool
        {
            public function name(): string
            {
                return 'exploding_tool';
            }

            public function description(): string
            {
                return 'Always fails — exists to exercise the failure log path.';
            }

            public function parametersSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args, ConversationContext $context): array|string
            {
                throw new RuntimeException('upstream CRM timed out');
            }
        };
    }
}
