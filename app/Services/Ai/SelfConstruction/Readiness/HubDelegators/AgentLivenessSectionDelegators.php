<?php

namespace App\Services\Ai\SelfConstruction\Readiness\HubDelegators;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionRuntimeGapMatrixService;
use App\Services\Ai\SelfConstruction\Readiness\CertificationWorkbenchEvaluator;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessCertificationChainQuartetProjector;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessPacketProjection;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessStatusProjection;

trait AgentLivenessSectionDelegators
{
    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentRunSync(array $options = []): array
    {
        return $this->agentLivenessSection()->agentRunSync($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentHeartbeat(array $options = []): array
    {
        return $this->agentLivenessSection()->agentHeartbeat($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentRunLiveness(array $options = []): array
    {
        return $this->agentLivenessSection()->agentRunLiveness($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentRunLivenessWrite(array $options = []): array
    {
        return $this->agentLivenessSection()->agentRunLivenessWrite($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentWakeupWrite(array $options = []): array
    {
        return $this->agentLivenessSection()->agentWakeupWrite($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentWakeupScheduler(array $options = []): array
    {
        return $this->agentLivenessSection()->agentWakeupScheduler($options);
    }
}
