<?php

namespace App\Services\Ai\Domain;

use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestrator;

class AtlasLearningOrchestrator implements AtlasDomainOrchestrator
{
    public function orchestratorId(): string
    {
        return 'AtlasLearningOrchestrator';
    }

    public function supportedDomains(): array
    {
        return ['learning'];
    }

    public function supportedFlows(): array
    {
        return ['learning.plan'];
    }

    public function maturity(): string
    {
        return 'scaffold';
    }
}
