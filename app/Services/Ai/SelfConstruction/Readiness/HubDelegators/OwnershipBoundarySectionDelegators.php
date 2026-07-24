<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness\HubDelegators;

/**
 * Compact section forwarders (full-pass density). Section: ownershipBoundarySection().
 */
trait OwnershipBoundarySectionDelegators
{
    public function agentReviewPostSignatureRunbook(array $options = []): array
    {
        return $this->ownershipBoundarySection()->agentReviewPostSignatureRunbook($options);
    }

    public function agentControlPlaneMultiAgentLoopCertificationStatus(array $options = []): array
    {
        return $this->ownershipBoundarySection()->agentControlPlaneMultiAgentLoopCertificationStatus($options);
    }

    public function agentWorkProduct(array $options = []): array
    {
        return $this->ownershipBoundarySection()->agentWorkProduct($options);
    }

    public function ownershipBoundary(array $options = []): array
    {
        return $this->ownershipBoundarySection()->ownershipBoundary($options);
    }

    public function agentControlPlaneOneShotWorkerPacketStatus(array $options = []): array
    {
        return $this->ownershipBoundarySection()->agentControlPlaneOneShotWorkerPacketStatus($options);
    }

}
