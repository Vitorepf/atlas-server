<?php

namespace Tests\Unit\Ai\Kernel\Pipeline;

use App\Services\Ai\Kernel\Pipeline\KernelPipelineContract;
use App\Services\Ai\Kernel\Pipeline\KernelPipelinePlanGuard;
use App\Services\Ai\Kernel\Pipeline\KernelPipelineStage;
use Tests\TestCase;

class KernelPipelinePlanGuardTest extends TestCase
{
    public function test_guard_accepts_compact_scaffold_kernel_pipeline_plan(): void
    {
        $report = (new KernelPipelinePlanGuard())->validate($this->validPlan());

        $this->assertTrue($report['ok']);
        $this->assertSame([], $report['errors']);
    }

    public function test_guard_rejects_tampered_stage_order_hash_and_execution_flags(): void
    {
        $plan = $this->validPlan();
        $plan['canonical_flow_hash'] = 'tampered';
        $plan['stage_order'] = array_reverse(KernelPipelineStage::orderedValues());
        $plan['provider_execution_allowed'] = true;
        $plan['execution_guards']['provider_execution_allowed'] = true;

        $report = (new KernelPipelinePlanGuard())->validate($plan);

        $this->assertFalse($report['ok']);
        $this->assertContains('kernel_pipeline.canonical_flow_hash does not match the canonical kernel flow.', $report['errors']);
        $this->assertContains('kernel_pipeline.stage_order must match the canonical kernel stage order.', $report['errors']);
        $this->assertContains('kernel_pipeline.provider_execution_allowed must remain false before runtime migration.', $report['errors']);
        $this->assertContains('kernel_pipeline.execution_guards.provider_execution_allowed must be false.', $report['errors']);
    }

    public function test_guard_uses_contract_allowlists_for_programming_surface_flow_and_input_mode(): void
    {
        $plan = $this->validPlan();
        $plan['input']['surface_id'] = 'atlas_unknown';
        $plan['input']['safe_hints']['flow'] = 'marketing.campaign';
        $plan['surface_binding']['surface'] = 'atlas_unknown';
        $plan['surface_binding']['input_mode'] = 'unknown_mode';
        $plan['surface_binding']['command'] = 'atlas:unknown';

        $report = (new KernelPipelinePlanGuard())->validate($plan);

        $this->assertFalse($report['ok']);
        $this->assertContains('kernel_pipeline.input.surface_id must be a canonical programming surface.', $report['errors']);
        $this->assertContains('kernel_pipeline.input.safe_hints.flow must be a programming flow.', $report['errors']);
        $this->assertContains('kernel_pipeline.surface_binding.surface must be a canonical programming surface.', $report['errors']);
        $this->assertContains('kernel_pipeline.surface_binding.input_mode is not recognized.', $report['errors']);
        $this->assertContains('kernel_pipeline.surface_binding.command is not recognized.', $report['errors']);
        $this->assertContains('atlas_cli_dev', KernelPipelineContract::programmingSurfaces());
        $this->assertContains('atlas_cli_forge', KernelPipelineContract::programmingSurfaces());
        $this->assertContains('programming.dev', KernelPipelineContract::programmingFlows());
        $this->assertContains('one_shot', KernelPipelineContract::programmingInputModes());
        $this->assertContains('atlas:cli:dev', KernelPipelineContract::programmingCommands());
    }

    public function test_guard_rejects_missing_or_tampered_surface_contract(): void
    {
        $guard = new KernelPipelinePlanGuard();
        $plan = $this->validPlan();

        $missing = $guard->validatePlanAndContract($plan, null);

        $this->assertFalse($missing['ok']);
        $this->assertContains('kernel_pipeline_contract must be present for dev/forge surfaces.', $missing['errors']);

        $contract = KernelPipelineContract::requiredSurfaceContract('KernelPipelineDevPlanBuilder');
        $contract['required'] = false;
        $contract['source'] = 'SurfaceCommand';
        $contract['surface_must_not_decide'] = false;
        $contract['provider_execution_blocked_until_runtime_migration'] = false;
        $contract['runtime_execution_blocked_until_runtime_migration'] = false;

        $tampered = $guard->validatePlanAndContract($plan, $contract);

        $this->assertFalse($tampered['ok']);
        $this->assertContains('kernel_pipeline_contract.required must be true.', $tampered['errors']);
        $this->assertContains('kernel_pipeline_contract.source is not recognized.', $tampered['errors']);
        $this->assertContains('kernel_pipeline_contract.surface_must_not_decide must be true.', $tampered['errors']);
        $this->assertContains('kernel_pipeline_contract.provider_execution_blocked_until_runtime_migration must be true.', $tampered['errors']);
        $this->assertContains('kernel_pipeline_contract.runtime_execution_blocked_until_runtime_migration must be true.', $tampered['errors']);
    }

    /**
     * @return array<string,mixed>
     */
    private function validPlan(): array
    {
        return [
            'schema_version' => KernelPipelineContract::SCHEMA_VERSION,
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
