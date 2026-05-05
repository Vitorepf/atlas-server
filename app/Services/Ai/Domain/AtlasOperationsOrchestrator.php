<?php

namespace App\Services\Ai\Domain;

use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestrator;
use App\Services\Ai\Kernel\Domain\SupportsScaffoldDomainExecution;

class AtlasOperationsOrchestrator implements AtlasDomainOrchestrator
{
    use SupportsScaffoldDomainExecution;

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
