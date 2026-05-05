<?php

namespace App\Services\Ai\Domain;

use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestrator;

class StandardResponseOrchestrator implements AtlasDomainOrchestrator
{
    public function orchestratorId(): string
    {
        return 'StandardResponseOrchestrator';
    }

    public function supportedDomains(): array
    {
        return ['general'];
    }

    public function supportedFlows(): array
    {
        return ['general.answer'];
    }

    public function maturity(): string
    {
        return 'scaffold';
    }
}
