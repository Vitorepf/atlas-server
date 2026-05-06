<?php

namespace App\Services\Ai\Kernel\Pipeline;

final class KernelPipelinePlanGuard
{
    /**
     * @param  array<string,mixed>  $plan
     * @return array{ok:bool,errors:array<int,string>,warnings:array<int,string>}
     */
    public function validate(array $plan): array
    {
        $errors = [];

        if (($plan['schema_version'] ?? null) !== KernelPipelineContract::SCHEMA_VERSION) {
            $errors[] = 'kernel_pipeline.schema_version must be '.KernelPipelineContract::SCHEMA_VERSION.'.';
        }

        if (($plan['mode'] ?? null) !== KernelPipelineContract::MODE) {
            $errors[] = 'kernel_pipeline.mode must be '.KernelPipelineContract::MODE.'.';
        }

        if (($plan['status'] ?? null) !== KernelPipelineContract::STATUS) {
            $errors[] = 'kernel_pipeline.status must be '.KernelPipelineContract::STATUS.'.';
        }

        if (($plan['canonical_flow_hash'] ?? null) !== KernelPipelineContract::canonicalFlowHash()) {
            $errors[] = 'kernel_pipeline.canonical_flow_hash does not match the canonical kernel flow.';
        }

        $stageOrder = $plan['stage_order'] ?? null;
        if ($stageOrder !== KernelPipelineStage::orderedValues()) {
            $errors[] = 'kernel_pipeline.stage_order must match the canonical kernel stage order.';
        }

        if (($plan['stage_count'] ?? null) !== count(KernelPipelineStage::orderedValues())) {
            $errors[] = 'kernel_pipeline.stage_count must match the canonical kernel stage count.';
        }

        if (($plan['provider_execution_allowed'] ?? null) !== false) {
            $errors[] = 'kernel_pipeline.provider_execution_allowed must remain false before runtime migration.';
        }

        if (($plan['runtime_execution_allowed'] ?? null) !== false) {
            $errors[] = 'kernel_pipeline.runtime_execution_allowed must remain false before runtime migration.';
        }

        $guards = is_array($plan['execution_guards'] ?? null) ? $plan['execution_guards'] : [];
        if (($guards['dry_run_effective'] ?? null) !== true) {
            $errors[] = 'kernel_pipeline.execution_guards.dry_run_effective must be true.';
        }

        if (($guards['provider_execution_allowed'] ?? null) !== false) {
            $errors[] = 'kernel_pipeline.execution_guards.provider_execution_allowed must be false.';
        }

        if (($guards['runtime_execution_allowed'] ?? null) !== false) {
            $errors[] = 'kernel_pipeline.execution_guards.runtime_execution_allowed must be false.';
        }

        if (($guards['surface_runtime_migration_allowed'] ?? null) !== false) {
            $errors[] = 'kernel_pipeline.execution_guards.surface_runtime_migration_allowed must be false.';
        }

        $input = is_array($plan['input'] ?? null) ? $plan['input'] : [];
        $surfaceId = $input['surface_id'] ?? null;
        if (! in_array($surfaceId, KernelPipelineContract::programmingSurfaces(), true)) {
            $errors[] = 'kernel_pipeline.input.surface_id must be a canonical programming surface.';
        }

        $flow = data_get($input, 'safe_hints.flow');
        if (! in_array($flow, KernelPipelineContract::programmingFlows(), true)) {
            $errors[] = 'kernel_pipeline.input.safe_hints.flow must be a programming flow.';
        }

        $binding = is_array($plan['surface_binding'] ?? null) ? $plan['surface_binding'] : [];
        if (! in_array($binding['surface'] ?? null, KernelPipelineContract::programmingSurfaces(), true)) {
            $errors[] = 'kernel_pipeline.surface_binding.surface must be a canonical programming surface.';
        }

        if (! in_array($binding['input_mode'] ?? null, KernelPipelineContract::programmingInputModes(), true)) {
            $errors[] = 'kernel_pipeline.surface_binding.input_mode is not recognized.';
        }

        if (! in_array($binding['command'] ?? null, KernelPipelineContract::programmingCommands(), true)) {
            $errors[] = 'kernel_pipeline.surface_binding.command is not recognized.';
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => [],
        ];
    }

    /**
     * @param  array<string,mixed>|null  $contract
     * @return array{ok:bool,errors:array<int,string>,warnings:array<int,string>}
     */
    public function validateSurfaceContract(?array $contract): array
    {
        $errors = [];

        if (! is_array($contract)) {
            return [
                'ok' => false,
                'errors' => ['kernel_pipeline_contract must be present for dev/forge surfaces.'],
                'warnings' => [],
            ];
        }

        if (($contract['required'] ?? null) !== true) {
            $errors[] = 'kernel_pipeline_contract.required must be true.';
        }

        $source = $contract['source'] ?? null;
        if (! is_string($source) || trim($source) === '') {
            $errors[] = 'kernel_pipeline_contract.source must be a non-empty string.';
        } elseif (! in_array($source, KernelPipelineContract::surfaceContractSources(), true)) {
            $errors[] = 'kernel_pipeline_contract.source is not recognized.';
        }

        if (($contract['surface_must_not_decide'] ?? null) !== true) {
            $errors[] = 'kernel_pipeline_contract.surface_must_not_decide must be true.';
        }

        if (($contract['provider_execution_blocked_until_runtime_migration'] ?? null) !== true) {
            $errors[] = 'kernel_pipeline_contract.provider_execution_blocked_until_runtime_migration must be true.';
        }

        if (($contract['runtime_execution_blocked_until_runtime_migration'] ?? null) !== true) {
            $errors[] = 'kernel_pipeline_contract.runtime_execution_blocked_until_runtime_migration must be true.';
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => [],
        ];
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>|null  $contract
     * @return array{ok:bool,errors:array<int,string>,warnings:array<int,string>}
     */
    public function validatePlanAndContract(array $plan, ?array $contract): array
    {
        $planReport = $this->validate($plan);
        $contractReport = $this->validateSurfaceContract($contract);

        return [
            'ok' => (bool) $planReport['ok'] && (bool) $contractReport['ok'],
            'errors' => [
                ...$planReport['errors'],
                ...$contractReport['errors'],
            ],
            'warnings' => [
                ...$planReport['warnings'],
                ...$contractReport['warnings'],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    public function assertValid(array $plan): void
    {
        $report = $this->validate($plan);
        if (! (bool) $report['ok']) {
            throw new KernelPipelinePlanViolation($report['errors']);
        }
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>|null  $contract
     */
    public function assertValidPlanAndContract(array $plan, ?array $contract): void
    {
        $report = $this->validatePlanAndContract($plan, $contract);
        if (! (bool) $report['ok']) {
            throw new KernelPipelinePlanViolation($report['errors']);
        }
    }
}
