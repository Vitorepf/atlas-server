<?php

namespace App\Services\Ai\Domain;

use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestrator;

class AtlasResearchOrchestrator implements AtlasDomainOrchestrator
{
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
