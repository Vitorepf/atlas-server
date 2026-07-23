<?php

namespace App\Services\Ai\SelfConstruction\Readiness\HubDelegators;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionRuntimeGapMatrixService;
use App\Services\Ai\SelfConstruction\Readiness\CertificationWorkbenchEvaluator;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessCertificationChainQuartetProjector;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessPacketProjection;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessStatusProjection;

trait OwnershipBoundarySectionDelegators
{
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentReviewPostSignatureRunbook(array $options = []) : array
    {
        return $this->ownershipBoundarySection()->agentReviewPostSignatureRunbook($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneMultiAgentLoopCertificationStatus(array $options = []) : array
    {
        return $this->ownershipBoundarySection()->agentControlPlaneMultiAgentLoopCertificationStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, artifact_type?: string|null, artifact_path?: string|null, artifact_hash?: string|null, summary?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentWorkProduct(array $options = []) : array
    {
        return $this->ownershipBoundarySection()->agentWorkProduct($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function ownershipBoundary(array $options = []) : array
    {
        return $this->ownershipBoundarySection()->ownershipBoundary($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneOneShotWorkerPacketStatus(array $options = []) : array
    {
        return $this->ownershipBoundarySection()->agentControlPlaneOneShotWorkerPacketStatus($options);
    }
}
