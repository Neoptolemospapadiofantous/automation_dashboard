<?php

namespace App\Events\Domain;

/**
 * Agent just transitioned draft → active (health check passed + creds saved).
 * The onboarding wizard's "you're done" step listens to this.
 */
class AgentActivated extends StateChanged {}
