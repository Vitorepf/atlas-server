<?php

namespace App\Services\Ai\Domain;

use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestrator;

class AtlasQaOrchestrator implements AtlasDomainOrchestrator
{
    public function orchestratorId(): string
    {
        return 'AtlasQaOrchestrator';
    }

    public function supportedDomains(): array
    {
        return ['qa'];
    }

    public function supportedFlows(): array
    {
        return ['qa.regression_review'];
    }

    public function maturity(): string
    {
        return 'scaffold';
    }
}
