<?php

namespace App\Services\Ai\Kernel\Repair;

enum RepairDecisionStatus: string
{
    case RepairAllowed = 'repair_allowed';
    case RepairBlocked = 'repair_blocked';
    case RepairExhausted = 'repair_exhausted';
    case NeedsHumanReview = 'needs_human_review';
}
