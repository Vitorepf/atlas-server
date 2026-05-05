<?php

namespace App\Services\Ai\Domain;

use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestrator;

class AtlasPersonalDevelopmentOrchestrator implements AtlasDomainOrchestrator
{
    public function orchestratorId(): string
    {
        return 'AtlasPersonalDevelopmentOrchestrator';
    }

    public function supportedDomains(): array
    {
        return ['personal_development'];
    }

    public function supportedFlows(): array
    {
        return ['personal_development.reflect'];
    }

    public function maturity(): string
    {
        return 'scaffold';
    }
}
