<?php

namespace App\Services\Voiceflow\Exceptions;

/**
 * The agent / config is missing something required to even attempt the call —
 * no API key, no projectID, no workspaceKey when one is required. Distinct
 * from AuthException: a 401 from Voiceflow vs "we never sent the request".
 */
final class MisconfiguredException extends VoiceflowException {}
