<?php

namespace App\Runtime\Exceptions;

use RuntimeException;

/**
 * Thrown by DeliverWebhook after recording a failed attempt, purely so
 * the queue retries with the job's backoff. Never surfaces to a user.
 */
class WebhookDeliveryFailed extends RuntimeException {}
