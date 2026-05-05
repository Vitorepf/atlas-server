<?php

namespace App\Services\Ai\Domain;

use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestrator;

class BackgroundSafetyOrchestrator implements AtlasDomainOrchestrator
{
    public function orchestratorId(): string
    {
        return 'BackgroundSafetyOrchestrator';
    }

    public function supportedDomains(): array
    {
        return ['background'];
    }

    public function supportedFlows(): array
    {
        return ['background.safe'];
    }

    public function maturity(): string
    {
        return 'scaffold';
    }
}
