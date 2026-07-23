<?php

namespace App\Services\Ai\SelfConstruction\Readiness\HubDelegators;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionRuntimeGapMatrixService;
use App\Services\Ai\SelfConstruction\Readiness\CertificationWorkbenchEvaluator;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessCertificationChainQuartetProjector;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessPacketProjection;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessStatusProjection;

trait AgentControlPlaneReplaySectionDelegators
{
    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneRuntimeSchemaPreflight(array $options = []): array
    {
        return $this->agentControlPlaneReplaySection()->agentControlPlaneRuntimeSchemaPreflight($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneChainIntegrityCertificationStatus(array $options = []): array
    {
        return $this->agentControlPlaneReplaySection()->agentControlPlaneChainIntegrityCertificationStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneDeterministicChainReplayStatus(array $options = []): array
    {
        return $this->agentControlPlaneReplaySection()->agentControlPlaneDeterministicChainReplayStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneReplaySnapshotStoreStatus(array $options = []): array
    {
        return $this->agentControlPlaneReplaySection()->agentControlPlaneReplaySnapshotStoreStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneReplaySnapshotStoreCapture(array $options = []): array
    {
        return $this->agentControlPlaneReplaySection()->agentControlPlaneReplaySnapshotStoreCapture($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneReplayDiffStatus(array $options = []): array
    {
        return $this->agentControlPlaneReplaySection()->agentControlPlaneReplayDiffStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneMacroSprintPromotionGateStatus(array $options = []): array
    {
        return $this->agentControlPlaneReplaySection()->agentControlPlaneMacroSprintPromotionGateStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneCertificationBaselineStatus(array $options = []): array
    {
        return $this->agentControlPlaneReplaySection()->agentControlPlaneCertificationBaselineStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneReleaseDossierStatus(array $options = []): array
    {
        return $this->agentControlPlaneReplaySection()->agentControlPlaneReleaseDossierStatus($options);
    }
}
