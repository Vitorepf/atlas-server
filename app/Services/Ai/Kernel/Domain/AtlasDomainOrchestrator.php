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

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function plan(string $flow, array $input = [], array $context = []): array;

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function execute(array $plan, array $context = []): array;

    /**
     * @param  array<string,mixed>  $failure
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function repair(array $failure, array $context = []): array;

    /**
     * @param  array<string,mixed>  $result
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function summarize(array $result, array $context = []): array;
}
