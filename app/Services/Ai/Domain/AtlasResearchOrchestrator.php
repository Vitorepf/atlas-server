<?php

namespace App\Services\Ai\Domain;

use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestrator;
use App\Services\Ai\Kernel\Domain\SupportsScaffoldDomainExecution;

class AtlasResearchOrchestrator implements AtlasDomainOrchestrator
{
    use SupportsScaffoldDomainExecution;

    public function orchestratorId(): string
    {
        return 'AtlasResearchOrchestrator';
    }

    public function supportedDomains(): array
    {
        return ['research'];
    }

    public function supportedFlows(): array
    {
        return ['research.quick', 'research.super'];
    }

    public function maturity(): string
    {
        return 'scaffold';
    }
}
