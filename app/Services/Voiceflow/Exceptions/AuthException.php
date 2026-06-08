<?php

namespace App\Services\Voiceflow\Exceptions;

/**
 * 401 / 403 from Voiceflow. Caller can retry once after refreshing credentials,
 * but only once — successive AuthExceptions mean the key is genuinely wrong.
 */
final class AuthException extends VoiceflowException {}
