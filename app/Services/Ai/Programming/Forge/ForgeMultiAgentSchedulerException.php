<?php

namespace App\Services\Ai\Programming\Forge;

use RuntimeException;

class ForgeMultiAgentSchedulerException extends RuntimeException
{
    public static function invalidRiskBand(string $band): self
    {
        return new self('atlas.forge.multi_agent_schedule: invalid risk_band '.$band);
    }

    public static function dependencyCycle(string $packetId): self
    {
        return new self('atlas.forge.multi_agent_schedule: dependency cycle detected involving packet '.$packetId);
    }

    public static function emptyTaskSummary(): self
    {
        return new self('atlas.forge.multi_agent_schedule: task_summary must not be empty');
    }
}
