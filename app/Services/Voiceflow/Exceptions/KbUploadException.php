<?php

namespace App\Services\Voiceflow\Exceptions;

/**
 * KB upload pre-flight validation failed before any HTTP request fired:
 *   - file path unreadable
 *   - empty payload
 *   - exceeds the 10 MB cap
 *   - fopen() returned false
 *
 * Distinct from MisconfiguredException — the system is configured, but the
 * caller handed us a payload we won't send. Lets `catch (VoiceflowException)`
 * cover upload preconditions alongside the HTTP-level cases.
 */
final class KbUploadException extends VoiceflowException {}
