<?php

namespace App\Services\Ai\Domain;

use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestrator;

class AtlasFinanceOrchestrator implements AtlasDomainOrchestrator
{
    public function orchestratorId(): string
    {
        return 'AtlasFinanceOrchestrator';
    }

    public function supportedDomains(): array
    {
        return ['finance'];
    }

    public function supportedFlows(): array
    {
        return ['finance.research'];
    }

    public function maturity(): string
    {
        return 'scaffold';
    }
}
