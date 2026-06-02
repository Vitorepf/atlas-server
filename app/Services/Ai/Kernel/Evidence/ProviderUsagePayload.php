<?php

namespace App\Services\Ai\Kernel\Evidence;

use App\Models\AiJob;
use App\Models\AiJobAttempt;
use App\Models\AiRouterDecision;
use App\Services\Ai\AiProviderResult;
use App\Services\Ai\Kernel\Decision\ComputeEffortPolicy;
use App\Services\Ai\Telemetry\AiCostEstimator;
use Illuminate\Support\Facades\Schema;

class ProviderUsagePayload
{
    public const SCHEMA_VERSION = 'atlas.provider_usage.v1';

    public function __construct(
        private readonly AiCostEstimator $costEstimator,
        private readonly ComputeEffortPolicy $computeEffort,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function called(AiJob $job, AiJobAttempt $attempt, array $kernelContext = []): array
    {
        return $this->base($job, $attempt, $kernelContext) + [
            'phase' => 'called',
            'started_at' => $attempt->started_at?->toJSON(),
            'finished_at' => null,
            'latency_seconds' => null,
            'exit_status' => 'started',
            'exit_code' => null,
            'quality_gate_result' => $this->qualityGateResult($job),
            'compute_effort_signal' => $this->computeEffortSignal($job, $attempt, null),
            'failure_reason' => null,
            'output_size_estimate' => null,
            'ledger_event_ref' => null,
        ] + $this->costPayload($job, $attempt);
    }

    /**
     * @return array<string,mixed>
     */
    public function returned(AiJob $job, AiJobAttempt $attempt, AiProviderResult $result, ?string $responseHash, array $kernelContext = []): array
    {
        return $this->base($job, $attempt, $kernelContext) + [
            'phase' => 'returned',
            'started_at' => $attempt->started_at?->toJSON(),
            'finished_at' => $attempt->finished_at?->toJSON() ?: now()->toJSON(),
            'latency_seconds' => $this->latencySeconds($result->durationMs),
            'exit_status' => $result->ok ? 'succeeded' : 'failed',
            'exit_code' => $result->exitCode,
            'quality_gate_result' => $this->qualityGateResult($job),
            'compute_effort_signal' => $this->computeEffortSignal($job, $attempt, $result),
            'failure_reason' => $result->errorCode,
            'output_size_estimate' => $this->sizeEstimate($result->output),
            'response_hash' => $responseHash,
            'command_hash' => $result->command ? hash('sha256', json_encode($result->command, JSON_THROW_ON_ERROR)) : null,
            'stdout_hash' => $result->stdout !== '' ? hash('sha256', $result->stdout) : null,
            'stderr_hash' => $result->stderr !== '' ? hash('sha256', $result->stderr) : null,
            'error_message_hash' => $result->errorMessage ? hash('sha256', $result->errorMessage) : null,
            'executive_runtime_packet' => $this->executiveRuntimePacket($result),
            'ledger_event_ref' => null,
        ] + $this->costPayload($job, $attempt, $result);
    }

    /**
     * @return array<string,mixed>
     */
    public function fallback(AiJob $job, AiJobAttempt $attempt, AiProviderResult $result, string $fallbackProvider, string $reason, array $kernelContext = []): array
    {
        return $this->base($job, $attempt, $kernelContext) + [
            'phase' => 'fallback',
            'started_at' => $attempt->started_at?->toJSON(),
            'finished_at' => $attempt->finished_at?->toJSON() ?: now()->toJSON(),
            'latency_seconds' => $this->latencySeconds($result->durationMs),
            'exit_status' => 'fallback',
            'exit_code' => $result->exitCode,
            'quality_gate_result' => $this->qualityGateResult($job),
            'compute_effort_signal' => $this->computeEffortSignal($job, $attempt, $result),
            'failure_reason' => $result->errorCode ?: $reason,
            'fallback_provider' => $fallbackProvider,
            'fallback_reason' => $reason,
            'output_size_estimate' => $this->sizeEstimate($result->output),
            'ledger_event_ref' => null,
        ] + $this->costPayload($job, $attempt, $result);
    }

    /**
     * @return array<string,mixed>
     */
    private function base(AiJob $job, AiJobAttempt $attempt, array $kernelContext): array
    {
        $receipt = $this->receiptV2($job);
        $routerDecision = $this->routerDecision($job);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'provider_cli' => (string) ($attempt->provider ?: $job->provider),
            'model_name_if_available' => $attempt->model ?: $job->model,
            'selected_model' => $this->firstString([
                data_get($job->payload, 'selected_model'),
                data_get($job->metadata, 'selected_model'),
                $attempt->model ?: $job->model,
            ]),
            'selected_model_alias' => $this->firstString([
                data_get($job->payload, 'selected_model_alias'),
                data_get($job->metadata, 'selected_model_alias'),
                data_get($job->payload, 'model_selection_contract.selected_model_alias'),
            ]),
            'operator_requested_model_alias' => $this->firstString([
                data_get($job->payload, 'operator_requested_model_alias'),
                data_get($job->metadata, 'operator_requested_model_alias'),
                data_get($job->payload, 'model_selection_contract.requested_model_alias'),
            ]),
            'model_family' => $this->firstString([
                data_get($job->payload, 'model_family'),
                data_get($job->metadata, 'model_family'),
                data_get($job->payload, 'model_selection_contract.model_family'),
            ]),
            'model_selection_source' => $this->firstString([
                data_get($job->payload, 'model_selection_source'),
                data_get($job->metadata, 'model_selection_source'),
                data_get($job->payload, 'model_selection_contract.selection_source'),
                data_get($job->payload, 'model_identity_source'),
            ]),
            'domain' => $this->firstString([
                data_get($receipt, 'domain'),
                data_get($job->payload, 'domain'),
                data_get($job->payload, 'programming_message_plan.domain'),
                data_get($job->metadata, 'domain'),
                data_get($kernelContext, 'domain'),
            ]),
            'flow' => $this->firstString([
                data_get($receipt, 'flow'),
                data_get($job->payload, 'flow'),
                data_get($job->payload, 'programming_message_plan.flow'),
                data_get($job->payload, 'atlas_workflow_mode'),
                data_get($job->metadata, 'flow'),
                data_get($kernelContext, 'flow'),
            ]),
            'task_type' => $this->firstString([
                data_get($receipt, 'metadata.task_profile.task_type'),
                data_get($job->payload, 'task_type'),
                data_get($job->payload, 'task_request.task_type'),
                data_get($job->payload, 'programming_message_plan.task_type'),
                data_get($job->metadata, 'task_type'),
                data_get($kernelContext, 'task_type'),
            ]),
            'specialist_profile' => $this->firstString([
                data_get($receipt, 'metadata.task_profile.specialist_profile'),
                data_get($receipt, 'metadata.specialist_profile'),
                data_get($receipt, 'specialist_profile'),
                data_get($job->payload, 'specialist_profile'),
                data_get($job->payload, 'task_request.specialist_profile'),
                data_get($job->payload, 'programming_message_plan.specialist_profile'),
                data_get($job->metadata, 'specialist_profile'),
                data_get($kernelContext, 'specialist_profile'),
            ]),
            'risk' => $this->firstString([
                data_get($receipt, 'risk'),
                data_get($receipt, 'metadata.task_profile.risk_level'),
                data_get($job->payload, 'risk'),
                data_get($job->payload, 'risk_level'),
                data_get($job->payload, 'task_request.risk_level'),
                data_get($job->metadata, 'risk'),
            ]),
            'trace_id' => $job->trace_id,
            'envelope_id' => $kernelContext['envelope_id'] ?? data_get($receipt, 'envelope_id'),
            'receipt_id' => $kernelContext['receipt_id'] ?? data_get($receipt, 'receipt_id'),
            'router_decision_id' => $routerDecision?->id,
            'selection_mode' => $routerDecision?->was_overridden ? 'manual_override' : 'auto',
            'router_selected_provider' => $routerDecision?->selected_provider,
            'router_fallback_provider' => $routerDecision?->fallback_provider,
            'attempts' => (int) $job->attempts,
            'attempt_number' => (int) $attempt->attempt_number,
            'repair_count' => $this->repairCount($job),
            'compute_effort' => $this->computeEffortLevel($job),
            'provider_effort' => $this->providerEffort($job, (string) ($attempt->provider ?: $job->provider)),
            'input_context_size_estimate' => $this->inputContextSizeEstimate($job),
            'user_acceptance' => $this->userAcceptance($job),
            'job_id' => $job->id,
            'attempt_id' => $attempt->id,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function computeEffortSignal(AiJob $job, AiJobAttempt $attempt, ?AiProviderResult $result): array
    {
        $provider = (string) ($attempt->provider ?: $job->provider);
        $contract = $this->computeEffortContract($job, $provider);

        return [
            'schema_version' => config('atlas.ai.compute_effort.measurement_schema', 'atlas.compute_effort_signal.v1'),
            'authority' => 'atlas_decide',
            'atlas_level' => $contract['atlas_level'] ?? $this->computeEffortLevel($job),
            'provider' => $provider,
            'provider_effort' => data_get($contract, 'provider_mapping.value'),
            'provider_control' => data_get($contract, 'provider_mapping.control'),
            'provider_control_status' => data_get($contract, 'provider_mapping.control_status'),
            'duration_ms' => $result?->durationMs ?? $attempt->duration_ms,
            'exit_status' => $result === null ? 'started' : ($result->ok ? 'succeeded' : 'failed'),
            'exit_code' => $result?->exitCode,
            'error_code' => $result?->errorCode,
            'attempt_number' => (int) $attempt->attempt_number,
            'job_status' => $job->status,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function executiveRuntimePacket(AiProviderResult $result): ?array
    {
        $mission = data_get($result->metadata, 'executive_mission');
        $packet = data_get($result->metadata, 'hermes_result_packet');
        $memoryAdapter = data_get($result->metadata, 'hermes_memory_adapter');
        $scheduleAdapter = data_get($result->metadata, 'hermes_schedule_adapter');
        $gatewayAdapter = data_get($result->metadata, 'hermes_gateway_adapter');
        $procedureAdapter = data_get($result->metadata, 'hermes_procedure_adapter');
        $scheduleActivation = data_get($result->metadata, 'hermes_schedule_activation');
        $memoryGateReview = data_get($result->metadata, 'hermes_memory_gate_review');
        $runtimeRouter = data_get($result->metadata, 'hermes_runtime_router');
        $capabilityInvocation = data_get($result->metadata, 'hermes_capability_invocation');
        $mcpAdapter = data_get($result->metadata, 'hermes_mcp_adapter');
        $delegationAdapter = data_get($result->metadata, 'hermes_delegation_adapter');
        if (! is_array($mission) && ! is_array($packet)) {
            return null;
        }

        return [
            'schema_version' => 'atlas.provider_usage.executive_runtime_packet_ref.v1',
            'runtime' => 'hermes_cli',
            'mission_id' => is_array($mission) ? ($mission['mission_id'] ?? null) : null,
            'mission_hash' => is_array($mission) ? ($mission['mission_hash'] ?? null) : null,
            'result_id' => is_array($packet) ? ($packet['result_id'] ?? null) : null,
            'result_hash' => is_array($packet) ? ($packet['result_hash'] ?? null) : null,
            'memory_delta_candidate_count' => is_array($packet) ? (int) data_get($packet, 'memory_gate.candidate_count', 0) : 0,
            'memory_adapter_status' => is_array($memoryAdapter) ? data_get($memoryAdapter, 'status') : null,
            'memory_adapter_persisted_count' => is_array($memoryAdapter) ? (int) data_get($memoryAdapter, 'persisted_count', 0) : 0,
            'memory_adapter_duplicate_count' => is_array($memoryAdapter) ? (int) data_get($memoryAdapter, 'duplicate_count', 0) : 0,
            'memory_adapter_receipt_hash' => is_array($memoryAdapter) ? data_get($memoryAdapter, 'receipt_hash') : null,
            'procedure_candidate_count' => is_array($packet) ? (int) data_get($packet, 'procedure_gate.candidate_count', 0) : 0,
            'schedule_candidate_count' => is_array($packet) ? (int) data_get($packet, 'schedule_gate.candidate_count', 0) : 0,
            'schedule_adapter_status' => is_array($scheduleAdapter) ? data_get($scheduleAdapter, 'status') : null,
            'schedule_adapter_persisted_count' => is_array($scheduleAdapter) ? (int) data_get($scheduleAdapter, 'persisted_count', 0) : 0,
            'schedule_adapter_duplicate_count' => is_array($scheduleAdapter) ? (int) data_get($scheduleAdapter, 'duplicate_count', 0) : 0,
            'schedule_adapter_receipt_hash' => is_array($scheduleAdapter) ? data_get($scheduleAdapter, 'receipt_hash') : null,
            'gateway_delivery_authority' => is_array($packet) ? data_get($packet, 'gateway.delivery_authority') : null,
            'gateway_adapter_status' => is_array($gatewayAdapter) ? data_get($gatewayAdapter, 'status') : null,
            'gateway_adapter_receipt_hash' => is_array($gatewayAdapter) ? data_get($gatewayAdapter, 'receipt_hash') : null,
            'procedure_adapter_status' => is_array($procedureAdapter) ? data_get($procedureAdapter, 'status') : null,
            'procedure_adapter_persisted_count' => is_array($procedureAdapter) ? (int) data_get($procedureAdapter, 'persisted_count', 0) : 0,
            'procedure_adapter_duplicate_count' => is_array($procedureAdapter) ? (int) data_get($procedureAdapter, 'duplicate_count', 0) : 0,
            'procedure_adapter_receipt_hash' => is_array($procedureAdapter) ? data_get($procedureAdapter, 'receipt_hash') : null,
            'schedule_activation_status' => is_array($scheduleActivation) ? data_get($scheduleActivation, 'status') : null,
            'schedule_activation_receipt_hash' => is_array($scheduleActivation) ? data_get($scheduleActivation, 'receipt_hash') : null,
            'schedule_activated_task_id' => is_array($scheduleActivation) ? data_get($scheduleActivation, 'activated_task_id') : null,
            'memory_gate_review_status' => is_array($memoryGateReview) ? data_get($memoryGateReview, 'status') : null,
            'memory_gate_review_receipt_hash' => is_array($memoryGateReview) ? data_get($memoryGateReview, 'receipt_hash') : null,
            'memory_gate_promoted_count' => is_array($memoryGateReview) ? (int) data_get($memoryGateReview, 'promoted_count', 0) : 0,
            'memory_gate_rejected_count' => is_array($memoryGateReview) ? (int) data_get($memoryGateReview, 'rejected_count', 0) : 0,
            'memory_gate_deduped_count' => is_array($memoryGateReview) ? (int) data_get($memoryGateReview, 'deduped_count', 0) : 0,
            'runtime_router_reason' => is_array($runtimeRouter) ? data_get($runtimeRouter, 'reason') : null,
            'runtime_router_role' => is_array($runtimeRouter) ? data_get($runtimeRouter, 'runtime_role') : null,
            'capability_invocation_status' => is_array($capabilityInvocation) ? data_get($capabilityInvocation, 'status') : null,
            'capability_invocation_receipt_hash' => is_array($capabilityInvocation) ? data_get($capabilityInvocation, 'receipt_hash') : null,
            'capability_resolved_count' => is_array($capabilityInvocation) ? (int) data_get($capabilityInvocation, 'resolved_count', 0) : 0,
            'capability_dropped_count' => is_array($capabilityInvocation) ? (int) data_get($capabilityInvocation, 'dropped_count', 0) : 0,
            'capability_manifest_hash' => data_get($result->metadata, 'hermes_capability_manifest_hash'),
            'mcp_adapter_status' => is_array($mcpAdapter) ? data_get($mcpAdapter, 'status') : null,
            'mcp_servers_allowed_count' => is_array($mcpAdapter) ? (int) data_get($mcpAdapter, 'servers_allowed', 0) : 0,
            'mcp_adapter_receipt_hash' => is_array($mcpAdapter) ? data_get($mcpAdapter, 'receipt_hash') : null,
            'delegation_adapter_status' => is_array($delegationAdapter) ? data_get($delegationAdapter, 'status') : null,
            'delegation_enabled' => is_array($delegationAdapter) ? (bool) data_get($delegationAdapter, 'delegation_enabled', false) : false,
            'delegation_adapter_receipt_hash' => is_array($delegationAdapter) ? data_get($delegationAdapter, 'receipt_hash') : null,
            'provider_is_executor_only' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function computeEffortContract(AiJob $job, string $provider): array
    {
        $contract = data_get($job->payload, 'compute_effort_contract')
            ?: data_get($job->payload, 'model_selection_contract.compute_effort')
            ?: data_get($job->metadata, 'compute_effort_contract')
            ?: data_get($job->metadata, 'model_selection_contract.compute_effort');

        if (is_array($contract)) {
            return $this->computeEffort->contract(
                requested: $contract['atlas_level'] ?? $contract['operator_requested_effort'] ?? null,
                provider: $provider,
                context: [
                    'domain' => data_get($job->payload, 'model_selection_contract.domain') ?: data_get($job->payload, 'domain'),
                    'flow' => data_get($job->payload, 'model_selection_contract.flow') ?: data_get($job->payload, 'flow'),
                    'task' => $job->input_text,
                ],
            );
        }

        return $this->computeEffort->contract(
            requested: data_get($job->payload, 'compute_effort') ?: data_get($job->metadata, 'compute_effort'),
            provider: $provider,
            context: [
                'domain' => data_get($job->payload, 'model_selection_contract.domain') ?: data_get($job->payload, 'domain'),
                'flow' => data_get($job->payload, 'model_selection_contract.flow') ?: data_get($job->payload, 'flow'),
                'task' => $job->input_text,
            ],
        );
    }

    private function computeEffortLevel(AiJob $job): string
    {
        $level = data_get($job->payload, 'compute_effort_contract.atlas_level')
            ?: data_get($job->payload, 'model_selection_contract.compute_effort.atlas_level')
            ?: data_get($job->payload, 'compute_effort')
            ?: data_get($job->metadata, 'compute_effort_contract.atlas_level')
            ?: data_get($job->metadata, 'model_selection_contract.compute_effort.atlas_level')
            ?: data_get($job->metadata, 'compute_effort');

        return $this->computeEffort->normalize($level) ?: $this->computeEffort->defaultLevel();
    }

    private function providerEffort(AiJob $job, string $provider): ?string
    {
        $value = data_get($this->computeEffortContract($job, $provider), 'provider_mapping.value');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function receiptV2(AiJob $job): array
    {
        $receipt = data_get($job->payload, 'decision_receipt.receipt_v2');
        if (is_array($receipt)) {
            return $receipt;
        }

        $receipt = data_get($job->metadata, 'decision_receipt.receipt_v2');
        if (is_array($receipt)) {
            return $receipt;
        }

        return [];
    }

    private function routerDecision(AiJob $job): ?AiRouterDecision
    {
        if (! $job->trace_id || ! Schema::hasTable('ai_router_decisions')) {
            return null;
        }

        return AiRouterDecision::query()
            ->where('trace_id', $job->trace_id)
            ->latest()
            ->first();
    }

    /**
     * @param  array<int,mixed>  $values
     */
    private function firstString(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function inputContextSizeEstimate(AiJob $job): int
    {
        return $this->sizeEstimate((string) $job->prompt)
            + $this->sizeEstimate((string) $job->input_text)
            + $this->sizeEstimate(json_encode($job->context_refs ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '')
            + $this->sizeEstimate(json_encode($job->payload ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }

    private function sizeEstimate(?string $value): int
    {
        return $value === null || $value === '' ? 0 : strlen($value);
    }

    private function latencySeconds(?int $durationMs): ?float
    {
        return $durationMs === null ? null : round($durationMs / 1000, 3);
    }

    private function repairCount(AiJob $job): int
    {
        return (int) (
            data_get($job->metadata, 'programming_completion.repair_count')
            ?? data_get($job->metadata, 'repair_count')
            ?? data_get($job->payload, 'repair_count')
            ?? 0
        );
    }

    private function qualityGateResult(AiJob $job): ?string
    {
        return $this->firstString([
            data_get($job->metadata, 'quality_gate_result'),
            data_get($job->metadata, 'programming_completion.quality_gate_result'),
            data_get($job->metadata, 'programming_completion.gate_result'),
            data_get($job->payload, 'quality_gate_result'),
        ]);
    }

    private function userAcceptance(AiJob $job): ?string
    {
        return $this->firstString([
            data_get($job->trace?->metadata, 'user_acceptance'),
            data_get($job->metadata, 'user_acceptance'),
            data_get($job->payload, 'user_acceptance'),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function costPayload(AiJob $job, AiJobAttempt $attempt, ?AiProviderResult $result = null): array
    {
        $estimate = $this->costEstimator->estimateProviderResult(
            $job,
            $result,
            $attempt->provider ?: $job->provider,
            $attempt->model ?: $job->model,
        );

        return [
            'prompt_tokens' => $estimate['prompt_tokens'],
            'completion_tokens' => $estimate['completion_tokens'],
            'total_tokens' => $estimate['total_tokens'],
            'estimated_tokens' => $estimate['estimated_tokens'],
            'token_source' => $estimate['token_source'],
            'cost_microusd' => $estimate['cost_microusd'],
            'cost_confidence' => $estimate['cost_confidence'],
            'cost_source' => $estimate['cost_source'],
            'cost_mode' => $estimate['cost_mode'],
        ];
    }
}
