<?php

namespace App\Events\Domain;

/**
 * Any Lead state transition. More specific events (LeadQualified, LeadAssigned)
 * fire alongside this one — listen to whichever level of granularity fits.
 */
class LeadStatusChanged extends StateChanged
{
}
