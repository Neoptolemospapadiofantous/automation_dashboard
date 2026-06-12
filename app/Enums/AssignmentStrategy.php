<?php

namespace App\Enums;

/**
 * How a lead gets assigned to a rep.
 */
enum AssignmentStrategy: string
{
    case RoundRobin = 'round_robin';
    case LeastLoaded = 'least_loaded';
    case Manual = 'manual';
    case Unassigned = 'unassigned';
}
