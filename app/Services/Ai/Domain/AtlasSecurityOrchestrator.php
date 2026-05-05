<?php

namespace App\Services\Ai\Domain;

use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestrator;

class AtlasSecurityOrchestrator implements AtlasDomainOrchestrator
{
    public function orchestratorId(): string
    {
        return 'AtlasSecurityOrchestrator';
    }

    public function supportedDomains(): array
    {
        return ['security'];
    }

    public function supportedFlows(): array
    {
        return ['security.threat_review'];
    }

    public function maturity(): string
    {
        return 'scaffold';
    }
}
