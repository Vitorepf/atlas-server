<?php

namespace App\Services\Ai\SelfConstruction\Readiness\HubDelegators;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionRuntimeGapMatrixService;
use App\Services\Ai\SelfConstruction\Readiness\CertificationWorkbenchEvaluator;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessCertificationChainQuartetProjector;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessPacketProjection;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessStatusProjection;

trait PacketQueuePolicySectionDelegators
{
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function completePacket(array $options = []): array
    {
        return $this->packetQueuePolicySection()->completePacket($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function aiSessionBootstrap(array $options = []): array
    {
        return $this->packetQueuePolicySection()->aiSessionBootstrap($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function packetQueue(array $options = []): array
    {
        return $this->packetQueuePolicySection()->packetQueue($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function collisionMatrix(array $options = []): array
    {
        return $this->packetQueuePolicySection()->collisionMatrix($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticCostImportPolicy(array $options = []): array
    {
        return $this->packetQueuePolicySection()->agentAutomaticCostImportPolicy($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticWorkProductCollectionPolicy(array $options = []): array
    {
        return $this->packetQueuePolicySection()->agentAutomaticWorkProductCollectionPolicy($options);
    }
}
