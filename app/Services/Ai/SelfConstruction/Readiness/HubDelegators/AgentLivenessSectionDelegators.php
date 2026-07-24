<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness\HubDelegators;

/**
 * Compact section forwarders (full-pass density). Section: agentLivenessSection().
 */
trait AgentLivenessSectionDelegators
{
    public function agentRunSync(array $options = []): array
    {
        return $this->agentLivenessSection()->agentRunSync($options);
    }

    public function agentHeartbeat(array $options = []): array
    {
        return $this->agentLivenessSection()->agentHeartbeat($options);
    }

    public function agentRunLiveness(array $options = []): array
    {
        return $this->agentLivenessSection()->agentRunLiveness($options);
    }

    public function agentRunLivenessWrite(array $options = []): array
    {
        return $this->agentLivenessSection()->agentRunLivenessWrite($options);
    }

    public function agentWakeupWrite(array $options = []): array
    {
        return $this->agentLivenessSection()->agentWakeupWrite($options);
    }

    public function agentWakeupScheduler(array $options = []): array
    {
        return $this->agentLivenessSection()->agentWakeupScheduler($options);
    }

}
