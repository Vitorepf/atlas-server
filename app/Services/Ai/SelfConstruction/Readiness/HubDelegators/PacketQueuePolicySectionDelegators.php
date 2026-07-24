<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness\HubDelegators;

/**
 * Compact section forwarders (full-pass density). Section: packetQueuePolicySection().
 */
trait PacketQueuePolicySectionDelegators
{
    public function completePacket(array $options = []): array
    {
        return $this->packetQueuePolicySection()->completePacket($options);
    }

    public function aiSessionBootstrap(array $options = []): array
    {
        return $this->packetQueuePolicySection()->aiSessionBootstrap($options);
    }

    public function packetQueue(array $options = []): array
    {
        return $this->packetQueuePolicySection()->packetQueue($options);
    }

    public function collisionMatrix(array $options = []): array
    {
        return $this->packetQueuePolicySection()->collisionMatrix($options);
    }

    public function agentAutomaticCostImportPolicy(array $options = []): array
    {
        return $this->packetQueuePolicySection()->agentAutomaticCostImportPolicy($options);
    }

    public function agentAutomaticWorkProductCollectionPolicy(array $options = []): array
    {
        return $this->packetQueuePolicySection()->agentAutomaticWorkProductCollectionPolicy($options);
    }

}
