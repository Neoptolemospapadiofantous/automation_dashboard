<?php

namespace Tests\Unit\Runtime;

use App\Runtime\Flow\FlowExecutor;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The knowledge-gap panel should collect genuine unanswered questions, not
 * the noise of the capture flow — one-word acknowledgements and the contact
 * details the agent asked for (which are visitor PII, not KB gaps).
 */
class GapWorthyTest extends TestCase
{
    private function isGapWorthy(string $message): bool
    {
        $executor = (new \ReflectionClass(FlowExecutor::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($executor, 'isGapWorthy');

        return (bool) $method->invoke($executor, $message);
    }

    public function test_real_questions_are_worthy(): void
    {
        $this->assertTrue($this->isGapWorthy('Do you support SAML single sign-on?'));
        $this->assertTrue($this->isGapWorthy('how much does the enterprise plan cost'));
    }

    public function test_short_acknowledgements_are_filtered(): void
    {
        $this->assertFalse($this->isGapWorthy('yes'));
        $this->assertFalse($this->isGapWorthy('ok thanks'));
        $this->assertFalse($this->isGapWorthy('sounds good'));
    }

    public function test_contact_detail_replies_are_filtered(): void
    {
        $this->assertFalse($this->isGapWorthy('my email is jane@acme.com and I am interested'));
        $this->assertFalse($this->isGapWorthy('you can reach me on 99 123 456 any time'));
    }
}
