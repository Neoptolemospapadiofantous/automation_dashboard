<?php

namespace App\Events\Domain;

/**
 * A Lead just became qualified. Auto-assignment listens to this.
 */
class LeadQualified extends StateChanged {}
