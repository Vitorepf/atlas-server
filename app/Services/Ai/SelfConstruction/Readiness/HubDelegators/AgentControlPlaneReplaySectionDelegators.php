<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness\HubDelegators;

/**
 * Compact section forwarders (full-pass density). Section: agentControlPlaneReplaySection().
 */
trait AgentControlPlaneReplaySectionDelegators
{
    public function agentControlPlaneRuntimeSchemaPreflight(array $options = []): array
    {
        return $this->agentControlPlaneReplaySection()->agentControlPlaneRuntimeSchemaPreflight($options);
    }

    public function agentControlPlaneChainIntegrityCertificationStatus(array $options = []): array
    {
        return $this->agentControlPlaneReplaySection()->agentControlPlaneChainIntegrityCertificationStatus($options);
    }

    public function agentControlPlaneDeterministicChainReplayStatus(array $options = []): array
    {
        return $this->agentControlPlaneReplaySection()->agentControlPlaneDeterministicChainReplayStatus($options);
    }

    public function agentControlPlaneReplaySnapshotStoreStatus(array $options = []): array
    {
        return $this->agentControlPlaneReplaySection()->agentControlPlaneReplaySnapshotStoreStatus($options);
    }

    public function agentControlPlaneReplaySnapshotStoreCapture(array $options = []): array
    {
        return $this->agentControlPlaneReplaySection()->agentControlPlaneReplaySnapshotStoreCapture($options);
    }

    public function agentControlPlaneReplayDiffStatus(array $options = []): array
    {
        return $this->agentControlPlaneReplaySection()->agentControlPlaneReplayDiffStatus($options);
    }

    public function agentControlPlaneMacroSprintPromotionGateStatus(array $options = []): array
    {
        return $this->agentControlPlaneReplaySection()->agentControlPlaneMacroSprintPromotionGateStatus($options);
    }

    public function agentControlPlaneCertificationBaselineStatus(array $options = []): array
    {
        return $this->agentControlPlaneReplaySection()->agentControlPlaneCertificationBaselineStatus($options);
    }

    public function agentControlPlaneReleaseDossierStatus(array $options = []): array
    {
        return $this->agentControlPlaneReplaySection()->agentControlPlaneReleaseDossierStatus($options);
    }

}
