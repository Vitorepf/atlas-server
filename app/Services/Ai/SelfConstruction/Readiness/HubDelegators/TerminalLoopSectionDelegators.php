<?php

namespace App\Services\Ai\SelfConstruction\Readiness\HubDelegators;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionRuntimeGapMatrixService;
use App\Services\Ai\SelfConstruction\Readiness\CertificationWorkbenchEvaluator;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessCertificationChainQuartetProjector;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessPacketProjection;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessStatusProjection;

trait TerminalLoopSectionDelegators
{
    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTerminalLoopHealthDigestStatus(array $options = []) : array
    {
        return $this->terminalLoopSection()->agentControlPlaneTerminalLoopHealthDigestStatus($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlaneTerminalLoopOperationalProofStatus(array $options = []) : array
    {
        return $this->terminalLoopSection()->agentControlPlaneTerminalLoopOperationalProofStatus($options);
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, model?: string|null, input_tokens?: int|string|null, output_tokens?: int|string|null, cost_usd?: float|string|null}  $options
     * @return array<string, mixed>
     */
    public function agentCostEvent(array $options = []) : array
    {
        return $this->terminalLoopSection()->agentCostEvent($options);
    }
}
