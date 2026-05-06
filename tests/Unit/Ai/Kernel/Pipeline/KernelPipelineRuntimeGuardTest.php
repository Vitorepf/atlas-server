<?php

namespace Tests\Unit\Ai\Kernel\Pipeline;

use App\Models\AiJob;
use App\Services\Ai\Kernel\Pipeline\KernelPipelineContract;
use App\Services\Ai\Kernel\Pipeline\KernelPipelineRuntimeGuard;
use App\Services\Ai\Kernel\Pipeline\KernelPipelineStage;
use Tests\TestCase;

class KernelPipelineRuntimeGuardTest extends TestCase
{
    public function test_runtime_guard_accepts_valid_job_contract_and_builds_audit_context(): void
    {
        $job = $this->jobWithPayload([
            'tenant_id' => 'tenant-runtime',
            'operator_id' => 'operator-runtime',
            'dev_execution_plan' => [
                'kernel_pipeline' => $this->validPlan(),
                'kernel_pipeline_contract' => KernelPipelineContract::requiredSurfaceContract('KernelPipelineDevPlanBuilder'),
            ],
        ]);

        $guard = app(KernelPipelineRuntimeGuard::class);

        $this->assertNull($guard->violationForJob($job));
        $this->assertSame('pipe_runtime_guard', $guard->auditablePlanForJob($job)['pipeline_id']);
        $this->assertSame([
            'tenant_id' => 'tenant-runtime',
            'operator_id' => 'operator-runtime',
            'envelope_id' => 'kernel_pipeline:runtime:job-runtime-1',
            'correlation_id' => 'job-runtime-1',
            'trace_id' => 'trace-runtime-1',
            'emitter_stage' => 'atlas.ai_worker.kernel_pipeline_runtime_guard',
            'emitter_version' => 'atlas.ai_worker.kernel_pipeline_runtime_guard.v1',
            'surface_contract' => KernelPipelineContract::requiredSurfaceContract('KernelPipelineDevPlanBuilder'),
        ], $guard->auditContextForJob($job));
    }

    public function test_runtime_guard_reports_missing_pipeline_for_dev_execution_plan(): void
    {
        $job = $this->jobWithPayload([
            'dev_execution_plan' => [
                'plan_id' => 'legacy-plan',
            ],
        ]);

        $guard = app(KernelPipelineRuntimeGuard::class);
        $violation = $guard->violationForJob($job);

        $this->assertSame('kernel_pipeline_contract_violation', $violation['error_code'] ?? null);
        $this->assertContains('kernel_pipeline must be present before programming provider execution.', $violation['violations'] ?? []);
        $this->assertSame('kernel_pipeline_runtime_missing', $guard->auditablePlanForJob($job, $violation)['pipeline_id']);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function jobWithPayload(array $payload): AiJob
    {
        return (new AiJob())->forceFill([
            'id' => 'job-runtime-1',
            'trace_id' => 'trace-runtime-1',
            'payload' => $payload,
            'metadata' => [],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function validPlan(): array
    {
        return [
            'schema_version' => KernelPipelineContract::SCHEMA_VERSION,
            'pipeline_id' => 'pipe_runtime_guard',
            'mode' => KernelPipelineContract::MODE,
            'status' => KernelPipelineContract::STATUS,
            'canonical_flow_hash' => KernelPipelineContract::canonicalFlowHash(),
            'stage_order' => KernelPipelineStage::orderedValues(),
            'stage_count' => count(KernelPipelineStage::orderedValues()),
            'provider_execution_allowed' => false,
            'runtime_execution_allowed' => false,
            'execution_guards' => [
                'dry_run_effective' => true,
                'provider_execution_allowed' => false,
                'runtime_execution_allowed' => false,
                'surface_runtime_migration_allowed' => false,
            ],
            'input' => [
                'surface_id' => 'atlas_ai_chat',
                'safe_hints' => [
                    'flow' => 'programming.dev',
                ],
            ],
            'surface_binding' => [
                'surface' => 'atlas_ai_chat',
                'command' => 'atlas:ai:chat',
                'input_mode' => 'declared_dev_plan',
            ],
        ];
    }
}
