<?php

namespace App\Services\Ai\Kernel\Domain;

trait SupportsScaffoldDomainExecution
{
    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function plan(string $flow, array $input = [], array $context = []): array
    {
        return $this->unsupportedSdkStage('plan', $flow, $input, $context);
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function execute(array $plan, array $context = []): array
    {
        return $this->unsupportedSdkStage('execute', (string) ($plan['flow'] ?? 'unknown'), $plan, $context);
    }

    /**
     * @param  array<string,mixed>  $failure
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function repair(array $failure, array $context = []): array
    {
        return $this->unsupportedSdkStage('repair', (string) ($failure['flow'] ?? 'unknown'), $failure, $context);
    }

    /**
     * @param  array<string,mixed>  $result
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function summarize(array $result, array $context = []): array
    {
        return [
            'schema_version' => 1,
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => (string) ($result['status'] ?? 'not_executed'),
            'summary' => 'Domain orchestrator is registered but does not expose an executable runtime yet.',
            'result' => $result,
            'context' => $context,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function unsupportedSdkStage(string $stage, string $flow, array $payload, array $context): array
    {
        return [
            'schema_version' => 1,
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'stage' => $stage,
            'flow' => $flow,
            'status' => 'not_supported',
            'reason' => 'domain_runtime_not_implemented',
            'payload' => $payload,
            'context' => $context,
        ];
    }
}
