<?php

namespace App\Services\Ai\SelfConstruction\Readiness\HubDelegators;

trait AgentControlPlaneSectionDelegators
{
    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentControlPlane(array $options = []): array
    {
        return $this->agentControlPlaneSection()->agentControlPlane($options);
    }
}
