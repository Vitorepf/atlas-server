<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness\HubDelegators;

/**
 * Compact section forwarders (full-pass density). Section: agentControlPlaneTaskLeaseSection().
 */
trait AgentControlPlaneTaskLeaseSectionDelegators
{
    public function agentControlPlaneTaskLeaseRecoveryStatus(array $options = []): array
    {
        return $this->agentControlPlaneTaskLeaseSection()->agentControlPlaneTaskLeaseRecoveryStatus($options);
    }

    public function agentControlPlaneClaimLeaseRuntimeStatus(array $options = []): array
    {
        return $this->agentControlPlaneTaskLeaseSection()->agentControlPlaneClaimLeaseRuntimeStatus($options);
    }

    public function agentControlPlaneTaskQueueClaimNextStatus(array $options = []): array
    {
        return $this->agentControlPlaneTaskLeaseSection()->agentControlPlaneTaskQueueClaimNextStatus($options);
    }

    public function agentControlPlaneTaskAutoReplenishmentStatus(array $options = []): array
    {
        return $this->agentControlPlaneTaskLeaseSection()->agentControlPlaneTaskAutoReplenishmentStatus($options);
    }

    public function agentControlPlaneTerminalWorkerBootstrapStatus(array $options = []): array
    {
        return $this->agentControlPlaneTaskLeaseSection()->agentControlPlaneTerminalWorkerBootstrapStatus($options);
    }

    public function agentControlPlaneAgentRuntimeRegistryHeartbeatStatus(array $options = []): array
    {
        return $this->agentControlPlaneTaskLeaseSection()->agentControlPlaneAgentRuntimeRegistryHeartbeatStatus($options);
    }

}
