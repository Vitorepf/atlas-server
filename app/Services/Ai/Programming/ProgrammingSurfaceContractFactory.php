<?php

namespace App\Services\Ai\Programming;

final class ProgrammingSurfaceContractFactory
{
    /**
     * @param  array<string,mixed>  $operatorOptions
     * @param  array<int,string>  $command
     * @return array<string,mixed>
     */
    public function fix(array $operatorOptions, array $command): array
    {
        return [
            'schema_version' => 'atlas.cli_fix.contract.v1',
            'surface' => 'atlas_cli_fix',
            'canonical_surface' => 'atlas_cli_dev',
            'target_command' => 'atlas:cli:dev',
            'target_surface' => 'atlas_cli_dev',
            'builder' => AtlasProgrammingSurfaceCommandBuilder::class,
            'flow' => 'programming.repair',
            'runtime' => 'dev_repair_executor',
            'orchestrator' => 'AtlasProgrammingOrchestrator',
            'programming_intent' => 'repair',
            'provider' => $operatorOptions['provider'] ?? null,
            'max_iterations' => ProgrammingIterationPolicy::normalize($operatorOptions['max_iterations'] ?? null),
            'quality_required' => true,
            'repair_required' => true,
            'dev_flags' => [
                'repair' => in_array('--repair', $command, true),
                'allow_write' => in_array('--allow-write', $command, true),
                'auto_test' => in_array('--auto-test', $command, true),
                'plan_only' => in_array('--plan-only', $command, true),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $resume
     * @param  array<string,mixed>  $operatorOptions
     * @param  array<int,string>  $command
     * @param  array<string,mixed>  $openBrain
     * @return array<string,mixed>
     */
    public function resume(array $resume, array $operatorOptions, array $command, array $openBrain): array
    {
        return [
            'schema_version' => 'atlas.cli_continue.resume_contract.v1',
            'surface' => 'atlas_cli_continue',
            'canonical_surface' => 'atlas_cli_dev',
            'target_command' => 'atlas:cli:dev',
            'target_surface' => 'atlas_cli_dev',
            'builder' => AtlasProgrammingSurfaceCommandBuilder::class,
            'plan_id' => (string) ($resume['plan_id'] ?? ''),
            'thread_id' => (string) ($resume['thread_id'] ?? ''),
            'trace_id' => (string) ($resume['trace_id'] ?? ''),
            'programming_profile' => $resume['programming_profile'] ?? null,
            'programming_intent' => $operatorOptions['programming_intent'] ?? ($operatorOptions['intent'] ?? null),
            'provider' => $operatorOptions['provider'] ?? null,
            'model' => $resume['model'] ?? null,
            'open_brain' => $openBrain,
            'dev_flags' => [
                'resume' => in_array('--resume='.(string) ($resume['plan_id'] ?? ''), $command, true),
                'forge' => in_array('--forge', $command, true),
                'repair' => in_array('--repair', $command, true),
                'allow_write' => in_array('--allow-write', $command, true),
                'auto_test' => in_array('--auto-test', $command, true),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>|null  $devPlan
     * @param  array<string,mixed>  $programmingMessagePlan
     * @param  array<string,mixed>|null  $dispatch
     * @return array<string,mixed>
     */
    public function chatDev(?array $devPlan, array $programmingMessagePlan, ?array $dispatch): array
    {
        return [
            'schema_version' => 'atlas.ai_chat.programming_contract.v1',
            'surface' => 'atlas_ai_chat',
            'mode' => 'dev',
            'orchestrator' => 'AtlasProgrammingOrchestrator',
            'parent_plan_id' => data_get($programmingMessagePlan, 'parent_plan_id') ?: data_get($devPlan, 'plan_id'),
            'programming_profile' => data_get($programmingMessagePlan, 'programming_profile'),
            'programming_flow' => data_get($programmingMessagePlan, 'policy_profile.profile_id'),
            'operator_intent' => data_get($programmingMessagePlan, 'operator_intent.kind'),
            'executor' => data_get($programmingMessagePlan, 'executor_decision.executor'),
            'dispatch_path' => data_get($dispatch, 'dispatch_path'),
            'kernel_pipeline_schema' => data_get($devPlan, 'kernel_pipeline.schema_version'),
            'kernel_pipeline_surface' => data_get($devPlan, 'kernel_pipeline.input.surface_id'),
            'kernel_pipeline_flow' => data_get($devPlan, 'kernel_pipeline.input.safe_hints.flow'),
            'kernel_pipeline_runtime' => data_get($devPlan, 'kernel_pipeline.input.safe_hints.runtime'),
            'kernel_pipeline_input_mode' => data_get($devPlan, 'kernel_pipeline.surface_binding.input_mode'),
            'kernel_pipeline_provider_execution_allowed' => (bool) data_get($devPlan, 'kernel_pipeline.provider_execution_allowed', false),
            'kernel_pipeline_contract_required' => (bool) data_get($devPlan, 'kernel_pipeline_contract.required', false),
        ];
    }

    /**
     * @param  array<string,mixed>  $devPlan
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    public function forge(array $devPlan, array $result): array
    {
        return [
            'schema_version' => 'atlas.cli_forge.contract.v1',
            'surface' => 'atlas_cli_forge',
            'profile' => 'forge',
            'flow' => 'programming.forge',
            'runtime' => 'engineering_harness',
            'orchestrator' => 'AtlasProgrammingOrchestrator',
            'executor' => data_get($devPlan, 'programming_session_plan.executor_decision.executor')
                ?: data_get($result, 'executor')
                ?: 'engineering_harness',
            'kernel_pipeline_flow' => data_get($devPlan, 'kernel_pipeline.input.safe_hints.flow'),
            'kernel_pipeline_runtime' => data_get($devPlan, 'kernel_pipeline.input.safe_hints.runtime'),
            'quality_required' => true,
            'evidence_required' => true,
            'harness_result_status' => data_get($result, 'status'),
            'harness_run_id' => data_get($result, 'harness_payload.run.id'),
        ];
    }
}
