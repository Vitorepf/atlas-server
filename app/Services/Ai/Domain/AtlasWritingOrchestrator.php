<?php

namespace App\Services\Ai\Domain;

use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestrator;
use App\Services\Ai\Kernel\Domain\SupportsScaffoldDomainExecution;

class AtlasWritingOrchestrator implements AtlasDomainOrchestrator
{
    use SupportsScaffoldDomainExecution;

    public function orchestratorId(): string
    {
        return 'AtlasWritingOrchestrator';
    }

    public function supportedDomains(): array
    {
        return ['writing'];
    }

    public function supportedFlows(): array
    {
        return ['writing.draft'];
    }

    public function maturity(): string
    {
        return 'scaffold';
    }
}
