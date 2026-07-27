<?php

namespace App\Services\Ai\SelfConstruction\Readiness\HubDelegators;

trait ForgeWorkspaceSectionDelegators
{
    public function forgeWorkspaceStatus(array $options = []): array
    {
        return $this->forgeWorkspaceSection()->forgeWorkspaceStatus($options);
    }
}
