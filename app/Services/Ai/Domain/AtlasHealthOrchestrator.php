<?php

namespace App\Services\Ai\Domain;

use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestrator;

class AtlasHealthOrchestrator implements AtlasDomainOrchestrator
{
    public function orchestratorId(): string
    {
        return 'AtlasHealthOrchestrator';
    }

    public function supportedDomains(): array
    {
        return ['health'];
    }

    public function supportedFlows(): array
    {
        return ['health.review'];
    }

    public function maturity(): string
    {
        return 'scaffold';
    }
}
