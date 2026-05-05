<?php

namespace App\Services\Ai\Kernel\Domain;

interface AtlasDomainOrchestrator
{
    public function orchestratorId(): string;

    /**
     * @return array<int,string>
     */
    public function supportedDomains(): array;

    /**
     * @return array<int,string>
     */
    public function supportedFlows(): array;

    public function maturity(): string;
}
