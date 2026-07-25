<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness\HubDelegators;

/**
 * PacketLifecycle projections.
 * Compact same-name section forwarders (full-pass density; method_exists preserved).
 */
trait PacketLifecycleSectionDelegators
{
    public function receiptPreview(array $options = []): array { return $this->packetLifecycleSection()->receiptPreview($options); }

    public function externalBlockers(array $options = []): array { return $this->packetLifecycleSection()->externalBlockers($options); }

    public function implementationPacket(array $options = []): array { return $this->packetLifecycleSection()->implementationPacket($options); }

    public function scopeValidator(array $options = []): array { return $this->packetLifecycleSection()->scopeValidator($options); }

    public function packetRunbook(array $options = []): array { return $this->packetLifecycleSection()->packetRunbook($options); }

    public function claimPacket(array $options = []): array { return $this->packetLifecycleSection()->claimPacket($options); }

    public function claimNextPacket(array $options = []): array { return $this->packetLifecycleSection()->claimNextPacket($options); }

    public function codexStartPacket(array $options = []): array { return $this->packetLifecycleSection()->codexStartPacket($options); }

    public function agentLaunchPlan(array $options = []): array { return $this->packetLifecycleSection()->agentLaunchPlan($options); }

    public function agentExecutionStatus(array $options = []): array { return $this->packetLifecycleSection()->agentExecutionStatus($options); }
}
