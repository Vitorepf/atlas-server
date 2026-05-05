<?php

namespace App\Services\Ai\Domain;

use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestrator;
use App\Services\Ai\Kernel\Domain\SupportsScaffoldDomainExecution;

class AtlasSecurityOrchestrator implements AtlasDomainOrchestrator
{
    use SupportsScaffoldDomainExecution;

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
