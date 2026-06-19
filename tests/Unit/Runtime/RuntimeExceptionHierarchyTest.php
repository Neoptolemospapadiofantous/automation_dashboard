<?php

namespace Tests\Unit\Runtime;

use App\Runtime\Exceptions\Misconfigured;
use App\Runtime\Exceptions\RuntimeException;
use App\Runtime\Exceptions\UpstreamUnavailable;
use PHPUnit\Framework\TestCase;

/**
 * The umbrella RuntimeException hierarchy: controllers catch RuntimeException
 * once and get a clean 503 for any engine failure. Pure type-relationship
 * checks — no framework. The DB/HTTP cases that prove the runtime actually
 * THROWS these live in tests/Feature/Runtime/ExceptionsTest.php.
 */
class RuntimeExceptionHierarchyTest extends TestCase
{
    public function test_misconfigured_extends_runtime_exception(): void
    {
        $this->assertInstanceOf(RuntimeException::class, new Misconfigured('no key'));
    }

    public function test_upstream_unavailable_extends_runtime_exception(): void
    {
        $this->assertInstanceOf(RuntimeException::class, new UpstreamUnavailable('provider 502'));
    }
}
