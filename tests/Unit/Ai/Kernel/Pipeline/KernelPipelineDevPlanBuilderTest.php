<?php

namespace Tests\Unit\Ai\Kernel\Pipeline;

use App\Services\Ai\Kernel\Pipeline\KernelPipelineContract;
use App\Services\Ai\Kernel\Pipeline\KernelPipelineDevPlanBuilder;
use App\Services\Ai\Kernel\Pipeline\KernelPipelinePlanViolation;
use App\Services\Ai\Kernel\Pipeline\KernelPipelineStage;
use Tests\TestCase;

class KernelPipelineDevPlanBuilderTest extends TestCase
{
    public function test_attaches_compact_programming_kernel_pipeline_contract(): void
    {
        $plan = app(KernelPipelineDevPlanBuilder::class)->attachProgrammingPlan(
            devPlan: ['plan_id' => 'plan_builder_test'],
            text: 'implemente uma tarefa',
            workspace: base_path(),
            surfaceId: 'atlas_cli_dev',
            command: 'atlas:cli:dev',
            inputMode: 'one_shot',
            programmingProfile: 'dev',
            flow: 'programming.dev',
            taskKind: 'implementation',
            runtime: 'dev_repair_executor',
            operatorId: 'tester',
        );

        $this->assertSame(KernelPipelineContract::SCHEMA_VERSION, data_get($plan, 'kernel_pipeline.schema_version'));
        $this->assertSame(KernelPipelineContract::MODE, data_get($plan, 'kernel_pipeline.mode'));
        $this->assertSame(KernelPipelineStage::orderedValues(), data_get($plan, 'kernel_pipeline.stage_order'));
        $this->assertSame('atlas_cli_dev', data_get($plan, 'kernel_pipeline.input.surface_id'));
        $this->assertSame('programming.dev', data_get($plan, 'kernel_pipeline.input.safe_hints.flow'));
        $this->assertSame('one_shot', data_get($plan, 'kernel_pipeline.surface_binding.input_mode'));
        $this->assertSame('KernelPipelineDevPlanBuilder', data_get($plan, 'kernel_pipeline_contract.source'));
        $this->assertSame(KernelPipelineContract::requiredSurfaceContract('KernelPipelineDevPlanBuilder'), $plan['kernel_pipeline_contract']);
        $this->assertFalse(data_get($plan, 'kernel_pipeline.provider_execution_allowed'));
        $this->assertFalse(data_get($plan, 'kernel_pipeline.runtime_execution_allowed'));
    }

    public function test_preserves_existing_valid_kernel_pipeline(): void
    {
        $existing = [
            'kernel_pipeline' => [
                'schema_version' => KernelPipelineContract::SCHEMA_VERSION,
                'pipeline_id' => 'pipe_existing',
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
            ],
            'kernel_pipeline_contract' => KernelPipelineContract::requiredSurfaceContract('KernelPipelineDevPlanBuilder'),
        ];

        $plan = app(KernelPipelineDevPlanBuilder::class)->attachProgrammingPlan(
            devPlan: $existing,
            text: 'nao substituir',
            workspace: base_path(),
            surfaceId: 'atlas_ai_chat',
            command: 'atlas:ai:chat',
            inputMode: 'declared_dev_plan',
            programmingProfile: 'dev',
            flow: 'programming.dev',
            taskKind: 'implementation',
            runtime: 'dev_repair_executor',
        );

        $this->assertSame($existing, $plan);
    }

    public function test_rejects_existing_invalid_kernel_pipeline_before_preserving_it(): void
    {
        $this->expectException(KernelPipelinePlanViolation::class);

        app(KernelPipelineDevPlanBuilder::class)->attachProgrammingPlan(
            devPlan: [
                'kernel_pipeline' => [
                    'pipeline_id' => 'pipe_invalid_existing',
                ],
            ],
            text: 'nao preservar contrato invalido',
            workspace: base_path(),
            surfaceId: 'atlas_ai_chat',
            command: 'atlas:ai:chat',
            inputMode: 'declared_dev_plan',
            programmingProfile: 'dev',
            flow: 'programming.dev',
            taskKind: 'implementation',
            runtime: 'dev_repair_executor',
        );
    }

    public function test_fails_closed_when_generated_plan_uses_disallowed_programming_surface(): void
    {
        $this->expectException(KernelPipelinePlanViolation::class);

        app(KernelPipelineDevPlanBuilder::class)->attachProgrammingPlan(
            devPlan: ['plan_id' => 'plan_invalid_surface'],
            text: 'nao aceitar surface desconhecida',
            workspace: base_path(),
            surfaceId: 'atlas_unknown',
            command: 'atlas:unknown',
            inputMode: 'one_shot',
            programmingProfile: 'dev',
            flow: 'programming.dev',
            taskKind: 'implementation',
            runtime: 'dev_repair_executor',
        );
    }

    public function test_fails_closed_when_generated_plan_uses_disallowed_command(): void
    {
        $this->expectException(KernelPipelinePlanViolation::class);

        app(KernelPipelineDevPlanBuilder::class)->attachProgrammingPlan(
            devPlan: ['plan_id' => 'plan_invalid_command'],
            text: 'nao aceitar comando desconhecido',
            workspace: base_path(),
            surfaceId: 'atlas_ai_chat',
            command: 'atlas:unknown',
            inputMode: 'declared_dev_plan',
            programmingProfile: 'dev',
            flow: 'programming.dev',
            taskKind: 'implementation',
            runtime: 'dev_repair_executor',
        );
    }
}
