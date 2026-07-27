<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Aael\Execution\TraceReplay;

interface AaelStepActor
{
    public function perform(int $stepIndex, string $action, mixed $input): string;
}
