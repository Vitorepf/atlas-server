<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness\HubDelegators;

/**
 * Compact section forwarders (full-pass density). Section: agentWakeupSection().
 */
trait AgentWakeupSectionDelegators
{
    public function agentStartPacket(array $options = []): array
    {
        return $this->agentWakeupSection()->agentStartPacket($options);
    }

    public function agentIntegrationReport(array $options = []): array
    {
        return $this->agentWakeupSection()->agentIntegrationReport($options);
    }

    public function agentControlPlaneTaskQueueCompleteDryRunStatus(array $options = []): array
    {
        return $this->agentWakeupSection()->agentControlPlaneTaskQueueCompleteDryRunStatus($options);
    }

    public function agentAdapterContract(array $options = []): array
    {
        return $this->agentWakeupSection()->agentAdapterContract($options);
    }

    public function agentWakeupClaim(array $options = []): array
    {
        return $this->agentWakeupSection()->agentWakeupClaim($options);
    }

}
