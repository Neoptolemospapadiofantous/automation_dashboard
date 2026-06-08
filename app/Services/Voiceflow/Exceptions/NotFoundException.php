<?php

namespace App\Services\Voiceflow\Exceptions;

/**
 * 404 from Voiceflow — wrong projectID, wrong document, environment alias
 * that doesn't exist, etc. Surfaces the upstream endpoint so the user gets
 * an actionable hint.
 */
final class NotFoundException extends VoiceflowException {}
