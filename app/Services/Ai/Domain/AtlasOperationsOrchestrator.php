<?php

namespace App\Services\Ai\Domain;

use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestrator;

class AtlasOperationsOrchestrator implements AtlasDomainOrchestrator
{
    public function orchestratorId(): string
    {
        return 'AtlasOperationsOrchestrator';
    }

    public function supportedDomains(): array
    {
        return ['operations'];
    }

    public function supportedFlows(): array
    {
        return ['operations.diagnostic'];
    }

    public function maturity(): string
    {
        return 'scaffold';
    }
}
