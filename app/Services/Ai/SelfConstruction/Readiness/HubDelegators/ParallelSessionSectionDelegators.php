<?php

namespace App\Services\Ai\SelfConstruction\Readiness\HubDelegators;

trait ParallelSessionSectionDelegators
{
    public function parallelSessionPlan(array $options = []): array
    {
        return $this->parallelSessionSection()->parallelSessionPlan($options);
    }
}
