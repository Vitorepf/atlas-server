<?php

namespace App\Services\Ai\SelfConstruction\Readiness\HubDelegators;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionRuntimeGapMatrixService;
use App\Services\Ai\SelfConstruction\Readiness\CertificationWorkbenchEvaluator;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessCertificationChainQuartetProjector;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessPacketProjection;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessStatusProjection;

trait AgentControlPlaneTaskLeaseSectionDelegators
{
    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTaskLeaseRecoveryStatus(array $options = []): array
    {
        return $this->agentControlPlaneTaskLeaseSection()->agentControlPlaneTaskLeaseRecoveryStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneClaimLeaseRuntimeStatus(array $options = []): array
    {
        return $this->agentControlPlaneTaskLeaseSection()->agentControlPlaneClaimLeaseRuntimeStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTaskQueueClaimNextStatus(array $options = []): array
    {
        return $this->agentControlPlaneTaskLeaseSection()->agentControlPlaneTaskQueueClaimNextStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTaskAutoReplenishmentStatus(array $options = []): array
    {
        return $this->agentControlPlaneTaskLeaseSection()->agentControlPlaneTaskAutoReplenishmentStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTerminalWorkerBootstrapStatus(array $options = []): array
    {
        return $this->agentControlPlaneTaskLeaseSection()->agentControlPlaneTerminalWorkerBootstrapStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneAgentRuntimeRegistryHeartbeatStatus(array $options = []): array
    {
        return $this->agentControlPlaneTaskLeaseSection()->agentControlPlaneAgentRuntimeRegistryHeartbeatStatus($options);
    }
}
