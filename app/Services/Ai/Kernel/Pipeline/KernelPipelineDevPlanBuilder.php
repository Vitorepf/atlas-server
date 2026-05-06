<?php

namespace App\Services\Ai\Kernel\Pipeline;

class KernelPipelineDevPlanBuilder
{
    public function __construct(
        private readonly ScaffoldAtlasKernelPipeline $pipeline,
        private readonly KernelPipelinePlanGuard $guard,
    ) {}

    /**
     * @param  array<string,mixed>  $devPlan
     * @return array<string,mixed>
     */
    public function attachProgrammingPlan(
        array $devPlan,
        string $text,
        string $workspace,
        string $surfaceId,
        string $command,
        string $inputMode,
        string $programmingProfile,
        string $flow,
        string $taskKind,
        string $runtime,
        string $inputType = 'text',
        ?string $operatorId = null,
    ): array {
        if (is_array($devPlan['kernel_pipeline'] ?? null)) {
            $this->guard->assertValidPlanAndContract(
                (array) $devPlan['kernel_pipeline'],
                is_array($devPlan['kernel_pipeline_contract'] ?? null) ? (array) $devPlan['kernel_pipeline_contract'] : null,
            );

            return $devPlan;
        }

        $plan = $this->pipeline->plan(PipelineInput::fromArray([
            'text' => $text,
            'input_type' => $inputType,
            'surface_id' => $surfaceId,
            'tenant_id' => 'atlas-single-tenant',
            'operator_id' => $operatorId ?: (get_current_user() ?: 'atlas_cli'),
            'locale' => 'pt-BR',
            'hints' => [
                'domain' => 'programming',
                'flow' => $flow,
                'mode' => $programmingProfile,
                'task' => $taskKind,
                'runtime' => $runtime,
            ],
            'metadata' => [
                'workspace' => $workspace,
                'input_mode' => $inputMode,
                'programming_profile' => $programmingProfile,
                'plan_id' => is_string($devPlan['plan_id'] ?? null) ? $devPlan['plan_id'] : null,
            ],
            'dry_run' => true,
        ]));

        $devPlan['kernel_pipeline'] = $this->compactPlan($plan, [
            'surface' => $surfaceId,
            'command' => $command,
            'input_mode' => $inputMode,
            'programming_profile' => $programmingProfile,
            'flow' => $flow,
        ]);
        $devPlan['kernel_pipeline_contract'] = $this->contract();
        $this->guard->assertValidPlanAndContract($devPlan['kernel_pipeline'], $devPlan['kernel_pipeline_contract']);

        return $devPlan;
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $surfaceBinding
     * @return array<string,mixed>
     */
    public function compactPlan(array $plan, array $surfaceBinding): array
    {
        return [
            'schema_version' => $plan['schema_version'] ?? null,
            'pipeline_id' => $plan['pipeline_id'] ?? null,
            'mode' => $plan['mode'] ?? null,
            'status' => $plan['status'] ?? null,
            'input' => $plan['input'] ?? [],
            'canonical_flow_hash' => $plan['canonical_flow_hash'] ?? null,
            'stage_order' => $plan['canonical_flow'] ?? [],
            'stage_count' => $plan['stage_count'] ?? null,
            'slot_manifest' => $plan['slot_manifest'] ?? [],
            'execution_guards' => $plan['execution_guards'] ?? [],
            'provider_execution_allowed' => (bool) ($plan['provider_execution_allowed'] ?? false),
            'runtime_execution_allowed' => (bool) ($plan['runtime_execution_allowed'] ?? false),
            'surface_binding' => $surfaceBinding,
        ];
    }

    /**
     * @return array<string,bool|string>
     */
    public function contract(): array
    {
        return KernelPipelineContract::requiredSurfaceContract('KernelPipelineDevPlanBuilder');
    }
}
