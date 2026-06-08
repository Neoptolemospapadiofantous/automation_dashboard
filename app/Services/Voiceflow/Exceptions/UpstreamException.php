<?php

namespace App\Services\Voiceflow\Exceptions;

/**
 * 5xx from Voiceflow or a connection-level failure that retries couldn't paper
 * over. Retryable as a class — caller may surface "service degraded" instead
 * of "you did something wrong".
 */
final class UpstreamException extends VoiceflowException {}
