<?php

namespace App\Provisioning;

use RuntimeException;

/**
 * Thrown by PoolAllocator when no available pool entries remain. The HTTP
 * layer maps this to a 503 with a "we're at capacity, contact us" message
 * via the exception handler in bootstrap/app.php.
 *
 * The signal an operator should care about: every PoolExhausted in logs
 * is a lost signup. Pair with vf:pool:list in a cron to alert when the
 * pool drops below a threshold.
 */
class PoolExhausted extends RuntimeException
{
    public function __construct(string $message = 'Voiceflow project pool is empty — no agents can be provisioned until the operator adds more entries.')
    {
        parent::__construct($message);
    }
}
