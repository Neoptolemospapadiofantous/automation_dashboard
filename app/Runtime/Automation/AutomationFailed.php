<?php

namespace App\Runtime\Automation;

use RuntimeException;

/**
 * The automation endpoint responded with a non-2xx status, or the transport
 * failed in a non-timeout way. Carries the upstream HTTP status (0 when there
 * was no response) so the dispatcher can record it on the automation_run.
 */
class AutomationFailed extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 0)
    {
        parent::__construct($message);
    }
}
