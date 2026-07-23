<?php

namespace App\Services\Ai\Programming\Sdd\Enums;


/**
 * Spec lifecycle status per templates-and-schemas.md:106.
 *
 * Transitions:
 *   draft → critiqued → approved → implemented → superseded
 *               ↓
 *           rejected (terminal until replaced by next version)
 */
enum SpecStatus: string
{
    case Draft = 'draft';
    case Critiqued = 'critiqued';
    case Approved = 'approved';
    case Implemented = 'implemented';
    case Superseded = 'superseded';
    case Rejected = 'rejected';

    public function canBeReplaced(): bool
    {
        return in_array($this, [self::Implemented, self::Superseded, self::Rejected], true);
    }

    public function isExecutable(): bool
    {
        return $this === self::Approved;
    }
}
