<?php

namespace App\Runtime\Automation;

use RuntimeException;

/**
 * The automation endpoint was too slow or unreachable within the configured
 * timeout. Distinct from AutomationFailed so the dispatcher can record a
 * 'timeout' status and degrade a sync call gracefully.
 */
class AutomationTimeout extends RuntimeException {}
