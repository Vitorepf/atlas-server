<?php

namespace App\Services\Ai\Kernel\Pipeline;

use App\Models\AiJob;
use App\Services\Ai\Support\AiStringListNormalizer;

class KernelPipelineRuntimeGuard
{
    public function __construct(
        private readonly KernelPipelinePlanGuard $guard,
    ) {}

    /**
     * @return array<string,mixed>|null
     */
    public function violationForJob(AiJob $job): ?array
    {
        if (! $this->requiresKernelPipelineContract($job)) {
            return null;
        }

        $plan = $this->pipelinePlanForJob($job);
        $contract = $this->surfaceContractForJob($job);

        if ($plan === null) {
            return $this->violation([
                'kernel_pipeline must be present before programming provider execution.',
            ], null, $contract);
        }

        $report = $this->guard->validatePlanAndContract($plan, $contract);
        if ((bool) ($report['ok'] ?? false)) {
            return null;
        }

        return $this->violation((array) ($report['errors'] ?? []), $plan, $contract);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function pipelinePlanForJob(AiJob $job): ?array
    {
        $payload = is_array($job->payload) ? $job->payload : [];
        $metadata = is_array($job->metadata) ? $job->metadata : [];

        foreach ([
            data_get($payload, 'dev_execution_plan.kernel_pipeline'),
            data_get($payload, 'kernel_pipeline'),
            data_get($metadata, 'dev_execution_plan.kernel_pipeline'),
            data_get($metadata, 'kernel_pipeline'),
        ] as $candidate) {
            if (is_array($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function surfaceContractForJob(AiJob $job): ?array
    {
        $payload = is_array($job->payload) ? $job->payload : [];
        $metadata = is_array($job->metadata) ? $job->metadata : [];

        foreach ([
            data_get($payload, 'dev_execution_plan.kernel_pipeline_contract'),
            data_get($payload, 'kernel_pipeline_contract'),
            data_get($metadata, 'dev_execution_plan.kernel_pipeline_contract'),
            data_get($metadata, 'kernel_pipeline_contract'),
        ] as $candidate) {
            if (is_array($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>|null  $violation
     * @return array<string,mixed>
     */
    public function auditablePlanForJob(AiJob $job, ?array $violation = null): array
    {
        $pipelineId = data_get($violation, 'pipeline_id');

        return $this->pipelinePlanForJob($job) ?: [
            'pipeline_id' => is_scalar($pipelineId) && trim((string) $pipelineId) !== ''
                ? trim((string) $pipelineId)
                : 'kernel_pipeline_runtime_missing',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function auditContextForJob(AiJob $job): array
    {
        return [
            'tenant_id' => data_get($job->payload, 'tenant_id', data_get($job->metadata, 'tenant_id', 'default')),
            'operator_id' => data_get($job->payload, 'operator_id', data_get($job->metadata, 'operator_id', 'system')),
            'envelope_id' => 'kernel_pipeline:runtime:'.$job->id,
            'correlation_id' => (string) $job->id,
            'trace_id' => $job->trace_id,
            'emitter_stage' => 'atlas.ai_worker.kernel_pipeline_runtime_guard',
            'emitter_version' => 'atlas.ai_worker.kernel_pipeline_runtime_guard.v1',
            'surface_contract' => $this->surfaceContractForJob($job),
        ];
    }

    private function requiresKernelPipelineContract(AiJob $job): bool
    {
        $payload = is_array($job->payload) ? $job->payload : [];
        $metadata = is_array($job->metadata) ? $job->metadata : [];

        foreach ([
            data_get($payload, 'dev_execution_plan'),
            data_get($payload, 'kernel_pipeline'),
            data_get($metadata, 'dev_execution_plan'),
            data_get($metadata, 'kernel_pipeline'),
        ] as $candidate) {
            if (is_array($candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int,string>  $errors
     * @param  array<string,mixed>|null  $plan
     * @param  array<string,mixed>|null  $contract
     * @return array<string,mixed>
     */
    private function violation(array $errors, ?array $plan, ?array $contract): array
    {
        $errors = AiStringListNormalizer::truthyTrimmedCastItemsToStrings($errors);

        return [
            'schema_version' => 1,
            'source' => 'KernelPipelineRuntimeGuard',
            'scope' => 'provider_runtime',
            'error_code' => 'kernel_pipeline_contract_violation',
            'message' => 'Kernel Pipeline contract invalid before provider execution.',
            'violations' => $errors,
            'pipeline_id' => is_array($plan) ? data_get($plan, 'pipeline_id') : null,
            'surface_id' => is_array($plan) ? (data_get($plan, 'input.surface_id') ?: data_get($plan, 'surface_binding.surface')) : null,
            'flow' => is_array($plan) ? (data_get($plan, 'input.safe_hints.flow') ?: data_get($plan, 'surface_binding.flow')) : null,
            'input_mode' => is_array($plan) ? data_get($plan, 'surface_binding.input_mode') : null,
            'contract_source' => is_array($contract) ? data_get($contract, 'source') : null,
        ];
    }
}
