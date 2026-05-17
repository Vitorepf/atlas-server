<?php

namespace App\Services\Ai\Router;

use App\Models\AiJob;
use App\Models\AiTrace;
use App\Support\Metadata;

class AtlasAiFlowStatusReadModel
{
    /**
     * @return array<string,mixed>
     */
    public function forTrace(AiTrace $trace): array
    {
        $runtime = $this->specialistFlowRuntime($trace);
        $execution = $this->specialistFlowExecution($trace);
        $devRuntime = $this->atlasDevRuntime($trace);
        $executionRecord = $trace->relationLoaded('specialistFlowExecution')
            ? $trace->specialistFlowExecution
            : null;
        $routerDecision = $trace->relationLoaded('routerDecision')
            ? $trace->routerDecision
            : null;

        return [
            'schema_version' => 'atlas.ai.flow_status.v1',
            'trace' => [
                'id' => $trace->id,
                'trace_key' => $trace->trace_key,
                'status' => $trace->status,
                'agent_slug' => $trace->agent_slug,
                'provider' => $trace->provider,
                'model' => $trace->model,
                'created_at' => $trace->created_at?->toJSON(),
                'updated_at' => $trace->updated_at?->toJSON(),
            ],
            'state' => $this->stateFor($routerDecision?->flow_id, $runtime, $execution, $devRuntime, $executionRecord),
            'router' => $routerDecision ? [
                'id' => $routerDecision->id,
                'schema_version' => $routerDecision->schema_version,
                'surface_id' => $routerDecision->surface_id,
                'flow_id' => $routerDecision->flow_id,
                'flow_origin' => $routerDecision->flow_origin,
                'command_intent' => $routerDecision->command_intent,
                'routing_reason' => $routerDecision->routing_reason,
                'routing_confidence' => $routerDecision->routing_confidence,
                'workspace_present' => (bool) $routerDecision->workspace_present,
                'mode' => $routerDecision->mode,
                'handoff_payload' => Metadata::forResponse($routerDecision->handoff_payload),
                'alternative_flow_ids' => Metadata::listForResponse($routerDecision->alternative_flow_ids),
            ] : null,
            'specialist_flow' => [
                'runtime' => $runtime,
                'execution' => $execution,
                'audit_record' => $executionRecord ? [
                    'id' => $executionRecord->id,
                    'flow_id' => $executionRecord->flow_id,
                    'handler_id' => $executionRecord->handler_id,
                    'handler_version' => $executionRecord->handler_version,
                    'status' => $executionRecord->status,
                    'runtime_receipt_id' => $executionRecord->runtime_receipt_id,
                    'runtime_contract_hash' => $executionRecord->runtime_contract_hash,
                    'delegation_status' => $executionRecord->delegation_status,
                    'delegation_target_flow_id' => $executionRecord->delegation_target_flow_id,
                    'audit_checks' => Metadata::listForResponse($executionRecord->audit_checks),
                    'response_shape' => Metadata::listForResponse($executionRecord->response_shape),
                    'quality_rubric' => Metadata::listForResponse(data_get($executionRecord->execution_payload, 'quality_rubric')),
                    'completion_checks' => Metadata::listForResponse(data_get($executionRecord->execution_payload, 'completion_checks')),
                    'failure_modes' => Metadata::listForResponse(data_get($executionRecord->execution_payload, 'failure_modes')),
                    'created_at' => $executionRecord->created_at?->toJSON(),
                ] : null,
            ],
            'atlas_dev_runtime' => $devRuntime,
            'audit' => [
                'receipt_id' => $executionRecord?->runtime_receipt_id ?: data_get($runtime, 'receipt.receipt_id'),
                'contract_hash' => $executionRecord?->runtime_contract_hash ?: data_get($runtime, 'receipt.contract_hash'),
                'required_evidence' => Metadata::listForResponse(data_get($runtime, 'required_evidence')),
                'forbidden_actions' => Metadata::listForResponse(data_get($runtime, 'forbidden_actions')),
                'audit_checks' => Metadata::listForResponse($executionRecord?->audit_checks ?: data_get($execution, 'audit_checks')),
                'quality_rubric' => Metadata::listForResponse(data_get($executionRecord?->execution_payload, 'quality_rubric') ?: data_get($execution, 'quality_rubric')),
                'completion_checks' => Metadata::listForResponse(data_get($executionRecord?->execution_payload, 'completion_checks') ?: data_get($execution, 'completion_checks')),
                'failure_modes' => Metadata::listForResponse(data_get($executionRecord?->execution_payload, 'failure_modes') ?: data_get($execution, 'failure_modes')),
            ],
            'telemetry' => $this->telemetryFor($trace),
            'ui' => [
                'label' => $this->labelFor($routerDecision?->flow_id, $executionRecord?->status ?: data_get($execution, 'status'), $devRuntime),
                'is_auditable' => (bool) (($executionRecord?->runtime_receipt_id ?: data_get($runtime, 'receipt.receipt_id'))),
                'next_action' => $this->nextActionFor($routerDecision?->flow_id, $executionRecord?->status ?: data_get($execution, 'status'), $devRuntime),
            ],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function specialistFlowRuntime(AiTrace $trace): ?array
    {
        return $this->firstPayloadSlice($trace, 'specialist_flow_runtime');
    }

    /**
     * @return array<string,mixed>|null
     */
    private function specialistFlowExecution(AiTrace $trace): ?array
    {
        return $this->firstPayloadSlice($trace, 'specialist_flow_execution');
    }

    /**
     * @return array<string,mixed>|null
     */
    private function atlasDevRuntime(AiTrace $trace): ?array
    {
        return $this->firstPayloadSlice($trace, 'atlas_dev_runtime');
    }

    /**
     * @return array<string,mixed>|null
     */
    private function firstPayloadSlice(AiTrace $trace, string $key): ?array
    {
        foreach ($this->jobsFor($trace) as $job) {
            $slice = data_get($job->payload, $key);
            if (is_array($slice) && $slice !== []) {
                return Metadata::forResponse($slice);
            }
        }

        $metadataSlice = data_get($trace->metadata, $key);

        return is_array($metadataSlice) && $metadataSlice !== []
            ? Metadata::forResponse($metadataSlice)
            : null;
    }

    /**
     * @return array<int,AiJob>
     */
    private function jobsFor(AiTrace $trace): array
    {
        $jobs = [];

        if ($trace->relationLoaded('job') && $trace->job) {
            $jobs[] = $trace->job;
        }

        if ($trace->relationLoaded('jobs') && $trace->jobs) {
            foreach ($trace->jobs as $job) {
                $jobs[$job->id] = $job;
            }
        }

        return array_values($jobs);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function telemetryFor(AiTrace $trace): ?array
    {
        $summary = $trace->relationLoaded('metricSummary') ? $trace->metricSummary : null;

        if (! $summary) {
            return null;
        }

        return [
            'id' => $summary->id,
            'runtime' => $summary->runtime,
            'task_type' => $summary->task_type,
            'router_mode' => $summary->router_mode,
            'router_selected_provider' => $summary->router_selected_provider,
            'router_was_overridden' => (bool) $summary->router_was_overridden,
            'scores' => [
                'final_quality_score' => $summary->final_quality_score,
                'final_efficiency_score' => $summary->final_efficiency_score,
                'context_efficiency_score' => $summary->context_efficiency_score,
            ],
            'score_components' => [
                'specialist_flow' => Metadata::forResponse(data_get($summary->score_components, 'specialist_flow')),
            ],
            'computed_at' => $summary->computed_at?->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>|null  $runtime
     * @param  array<string,mixed>|null  $execution
     * @param  array<string,mixed>|null  $devRuntime
     */
    private function stateFor(?string $flowId, ?array $runtime, ?array $execution, ?array $devRuntime, mixed $executionRecord): string
    {
        if ($devRuntime !== null) {
            return 'atlas_dev_runtime';
        }

        if ($flowId === 'atlas_forge') {
            return 'forge_required';
        }

        if ($executionRecord !== null) {
            return 'audit_recorded';
        }

        if ($execution !== null) {
            return (string) (data_get($execution, 'status') ?: 'ready_for_provider');
        }

        if ($runtime !== null) {
            return 'runtime_contract_ready';
        }

        return $flowId ? 'routed' : 'missing_router_decision';
    }

    /**
     * @param  array<string,mixed>|null  $devRuntime
     */
    private function labelFor(?string $flowId, ?string $executionStatus, ?array $devRuntime): string
    {
        if ($devRuntime !== null) {
            return 'Atlas Dev runtime pronto';
        }

        return match ($flowId) {
            'atlas_forge' => 'Encaminhado para Atlas Forge',
            'atlas_research' => 'Pesquisa com contrato de fontes',
            'atlas_explain' => 'Explicacao read-only auditavel',
            'atlas_debug' => 'Debug com triagem auditavel',
            'atlas_review' => 'Review findings-first auditavel',
            'atlas_plan' => 'Plano de engenharia auditavel',
            'atlas_conversation' => 'Conversa direta',
            default => $executionStatus ? 'Flow especialista pronto' : 'Router pendente',
        };
    }

    /**
     * @param  array<string,mixed>|null  $devRuntime
     */
    private function nextActionFor(?string $flowId, ?string $executionStatus, ?array $devRuntime): string
    {
        if ($devRuntime !== null) {
            return 'render_atlas_dev_controls';
        }

        if ($flowId === 'atlas_forge') {
            return 'open_forge_surface';
        }

        if ($executionStatus === 'delegated') {
            return 'follow_delegation_target';
        }

        if ($executionStatus !== null) {
            return 'wait_provider_response';
        }

        return $flowId ? 'wait_runtime_contract' : 'inspect_router_input';
    }
}
