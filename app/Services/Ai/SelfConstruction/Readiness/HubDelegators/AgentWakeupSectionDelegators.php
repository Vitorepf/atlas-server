<?php

namespace App\Services\Ai\SelfConstruction\Readiness\HubDelegators;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionRuntimeGapMatrixService;
use App\Services\Ai\SelfConstruction\Readiness\CertificationWorkbenchEvaluator;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessCertificationChainQuartetProjector;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessPacketProjection;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessStatusProjection;

trait AgentWakeupSectionDelegators
{
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentStartPacket(array $options = []) : array
    {
        return $this->agentWakeupSection()->agentStartPacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentIntegrationReport(array $options = []) : array
    {
        return $this->agentWakeupSection()->agentIntegrationReport($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTaskQueueCompleteDryRunStatus(array $options = []) : array
    {
        return $this->agentWakeupSection()->agentControlPlaneTaskQueueCompleteDryRunStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAdapterContract(array $options = []) : array
    {
        return $this->agentWakeupSection()->agentAdapterContract($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentWakeupClaim(array $options = []) : array
    {
        return $this->agentWakeupSection()->agentWakeupClaim($options);
    }
}
