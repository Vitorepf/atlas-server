<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Quaternity\IntentIngest;

/** Closed set of receipt decisions the ledger accepts. */
final class IntentReceiptDecision
{
    public const ACCEPTED = 'accepted';

    public const REJECTED_VAGUE = 'rejected_vague';

    public const REJECTED_SCHEMA = 'rejected_schema';

    public const QUEUED_FOR_DECIDER = 'queued_for_decider';

    public const ALL = [
        self::ACCEPTED,
        self::REJECTED_VAGUE,
        self::REJECTED_SCHEMA,
        self::QUEUED_FOR_DECIDER,
    ];
}
