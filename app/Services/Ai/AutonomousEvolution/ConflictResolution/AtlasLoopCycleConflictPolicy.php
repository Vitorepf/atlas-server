<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\ConflictResolution;

final class AtlasLoopCycleConflictPolicy
{
    public const CLAUSE_DIVERGENT = 'R-DIV';

    public const CLAUSE_IDENTICAL = 'R-IDENT';

    public const CLAUSE_STITCH = 'R-STITCH';

    public const DECISION_REFUSE = 'REFUSE';

    public const DECISION_AUTO_MERGE_IDENTICAL = 'AUTO_MERGE_IDENTICAL';

    public const DECISION_STITCH_PENDING_OPERATOR = 'STITCH_PENDING_OPERATOR';
}
