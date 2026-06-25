<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Aael\Execution\Debugger;

enum PauseGateDecision: string
{
    case resumed = 'resumed';
    case timed_out = 'timed_out';
    case aborted = 'aborted';
}
