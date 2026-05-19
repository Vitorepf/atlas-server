<?php

namespace App\Http\Resources;

use App\Services\Ai\RouterRuntime\AtlasHyperflowEntryService;
use App\Support\AiAttachmentPayload;
use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiTraceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trace_key' => $this->trace_key,
            'thread_id' => $this->thread_id,
            'session_id' => $this->session_id,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'status' => $this->status,
            'operator_input' => $this->operator_input,
            'intent' => $this->intent,
            'agent_slug' => $this->agent_slug,
            'provider' => $this->provider,
            'model' => $this->model,
            'skill_versions' => Metadata::forResponse($this->skill_versions),
            'context_refs' => Metadata::listForResponse($this->context_refs),
            'prompt_hash' => $this->prompt_hash,
            'response_hash' => $this->response_hash,
            'response_text' => $this->response_text,
            'latency_ms' => $this->latency_ms,
            'stream_url' => url("/api/ai/interactions/{$this->id}/stream"),
            'feedback_score' => $this->feedback_score,
            'feedback_action' => $this->feedback_action,
            'feedback_comment' => $this->feedback_comment,
            'completed_at' => $this->completed_at?->toJSON(),
            'metadata' => Metadata::forResponse($this->metadata),
            'atlas_decide_execution' => Metadata::forResponse(data_get($this->metadata, 'atlas_decide_execution')),
            'hyperflow_runtime' => $this->hyperflowRuntimeForResponse(),
            'hyperflow' => $this->hyperflowFlatForResponse(),
            'atlas_dev_runtime' => $this->atlasDevRuntimeForResponse(),
            'specialist_flow_runtime' => $this->specialistFlowRuntimeForResponse(),
            'specialist_flow_execution' => $this->specialistFlowExecutionForResponse(),
            'attachments' => $this->publicAttachments(),
            'thread' => $this->whenLoaded('thread', fn () => $this->thread ? new AiThreadResource($this->thread) : null),
            'session' => $this->whenLoaded('session', fn () => $this->session ? new AiSessionResource($this->session) : null),
            'job' => $this->whenLoaded('job', fn () => new AiJobResource($this->job)),
            'jobs' => $this->whenLoaded('jobs', fn () => AiJobResource::collection($this->jobs)->resolve()),
            'router_decision' => $this->whenLoaded('routerDecision', fn () => $this->routerDecision ? $this->routerDecisionForResponse() : null),
            'atlas_decision' => $this->whenLoaded('atlasDecision', fn () => $this->atlasDecision ? $this->atlasDecisionForResponse() : null),
            'specialist_flow_execution_record' => $this->whenLoaded('specialistFlowExecution', fn () => $this->specialistFlowExecution ? $this->specialistFlowExecutionRecordForResponse() : null),
            'decision_receipt' => $this->decisionReceiptForResponse(),
            'stream_events' => $this->whenLoaded('streamEvents', fn () => AiStreamEventResource::collection($this->streamEvents)->resolve()),
            'quality_evaluation' => $this->whenLoaded('qualityEvaluation', fn () => $this->qualityEvaluation ? new AiQualityEvaluationResource($this->qualityEvaluation) : null),
            'quality_actions' => $this->whenLoaded('qualityActions', fn () => AiQualityActionResource::collection($this->qualityActions)->resolve()),
            'tool_events' => $this->whenLoaded('toolEvents', fn () => AiToolEventResource::collection($this->toolEvents)->resolve()),
            'metric_summary' => $this->whenLoaded('metricSummary', fn () => $this->metricSummary ? new AiTraceMetricSummaryResource($this->metricSummary) : null),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function atlasDecisionForResponse(): array
    {
        return [
            'id' => $this->atlasDecision->id,
            'trace_id' => $this->atlasDecision->trace_id,
            'router_decision_id' => $this->atlasDecision->router_decision_id,
            'policy_version' => $this->atlasDecision->policy_version,
            'decision_mode' => $this->atlasDecision->decision_mode,
            'route_mode' => $this->atlasDecision->route_mode,
            'task_type' => $this->atlasDecision->task_type,
            'risk_level' => $this->atlasDecision->risk_level,
            'context_strategy' => $this->atlasDecision->context_strategy,
            'execution_strategy' => $this->atlasDecision->execution_strategy,
            'selected_provider' => $this->atlasDecision->selected_provider,
            'selected_model' => $this->atlasDecision->selected_model,
            'fallback_provider' => $this->atlasDecision->fallback_provider,
            'operator_requested_provider' => $this->atlasDecision->operator_requested_provider,
            'requested_provider' => $this->atlasDecision->requested_provider,
            'was_overridden' => (bool) $this->atlasDecision->was_overridden,
            'confidence_score' => $this->atlasDecision->confidence_score,
            'signals' => Metadata::forResponse($this->atlasDecision->signals),
            'candidates' => Metadata::listForResponse($this->atlasDecision->candidates),
            'constraints' => Metadata::forResponse($this->atlasDecision->constraints),
            'metrics_snapshot' => Metadata::forResponse($this->atlasDecision->metrics_snapshot),
            'task_profile' => Metadata::forResponse($this->atlasDecision->task_profile),
            'execution_graph' => Metadata::forResponse($this->atlasDecision->execution_graph),
            'reason' => $this->atlasDecision->reason,
            'created_at' => $this->atlasDecision->created_at?->toJSON(),
            'updated_at' => $this->atlasDecision->updated_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function routerDecisionForResponse(): array
    {
        return [
            'id' => $this->routerDecision->id,
            'schema_version' => $this->routerDecision->schema_version,
            'surface_id' => $this->routerDecision->surface_id,
            'flow_id' => $this->routerDecision->flow_id,
            'flow_origin' => $this->routerDecision->flow_origin,
            'command_intent' => $this->routerDecision->command_intent,
            'routing_reason' => $this->routerDecision->routing_reason,
            'routing_confidence' => $this->routerDecision->routing_confidence,
            'workspace_present' => (bool) $this->routerDecision->workspace_present,
            'mode' => $this->routerDecision->mode,
            'selected_provider' => $this->routerDecision->selected_provider,
            'fallback_provider' => $this->routerDecision->fallback_provider,
            'signals' => Metadata::forResponse($this->routerDecision->signals),
            'handoff_payload' => Metadata::forResponse($this->routerDecision->handoff_payload),
            'alternative_flow_ids' => Metadata::listForResponse($this->routerDecision->alternative_flow_ids),
            'reason' => $this->routerDecision->reason,
            'was_overridden' => (bool) $this->routerDecision->was_overridden,
            'created_at' => $this->routerDecision->created_at?->toJSON(),
            'updated_at' => $this->routerDecision->updated_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function specialistFlowExecutionRecordForResponse(): array
    {
        return [
            'id' => $this->specialistFlowExecution->id,
            'trace_id' => $this->specialistFlowExecution->trace_id,
            'router_decision_id' => $this->specialistFlowExecution->router_decision_id,
            'runtime_schema_version' => $this->specialistFlowExecution->runtime_schema_version,
            'execution_schema_version' => $this->specialistFlowExecution->execution_schema_version,
            'flow_id' => $this->specialistFlowExecution->flow_id,
            'handler_id' => $this->specialistFlowExecution->handler_id,
            'handler_version' => $this->specialistFlowExecution->handler_version,
            'status' => $this->specialistFlowExecution->status,
            'runtime_receipt_id' => $this->specialistFlowExecution->runtime_receipt_id,
            'runtime_contract_hash' => $this->specialistFlowExecution->runtime_contract_hash,
            'delegation_status' => $this->specialistFlowExecution->delegation_status,
            'delegation_target_flow_id' => $this->specialistFlowExecution->delegation_target_flow_id,
            'receipt' => Metadata::forResponse($this->specialistFlowExecution->receipt),
            'delegation' => Metadata::forResponse($this->specialistFlowExecution->delegation),
            'audit_checks' => Metadata::listForResponse($this->specialistFlowExecution->audit_checks),
            'response_shape' => Metadata::listForResponse($this->specialistFlowExecution->response_shape),
            'quality_rubric' => Metadata::listForResponse(data_get($this->specialistFlowExecution->execution_payload, 'quality_rubric')),
            'completion_checks' => Metadata::listForResponse(data_get($this->specialistFlowExecution->execution_payload, 'completion_checks')),
            'failure_modes' => Metadata::listForResponse(data_get($this->specialistFlowExecution->execution_payload, 'failure_modes')),
            'created_at' => $this->specialistFlowExecution->created_at?->toJSON(),
            'updated_at' => $this->specialistFlowExecution->updated_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decisionReceiptForResponse(): ?array
    {
        $metadataReceipt = is_array(data_get($this->metadata, 'decision_receipt'))
            ? data_get($this->metadata, 'decision_receipt')
            : [];
        $atlasDecision = $this->resource->relationLoaded('atlasDecision') ? $this->atlasDecision : null;
        $routerDecision = $this->resource->relationLoaded('routerDecision') ? $this->routerDecision : null;

        if ($atlasDecision) {
            return array_merge(Metadata::forResponse($metadataReceipt), [
                'decision_id' => $atlasDecision->id,
                'trace_id' => $this->id,
                'router_decision_id' => $atlasDecision->router_decision_id,
                'policy_version' => $atlasDecision->policy_version,
                'decision_mode' => $atlasDecision->decision_mode,
                'route_mode' => $atlasDecision->route_mode,
                'task_type' => $atlasDecision->task_type,
                'risk_level' => $atlasDecision->risk_level,
                'context_strategy' => $atlasDecision->context_strategy,
                'execution_strategy' => $atlasDecision->execution_strategy,
                'selected_provider' => $atlasDecision->selected_provider,
                'selected_model' => $atlasDecision->selected_model,
                'fallback_provider' => $atlasDecision->fallback_provider,
                'operator_requested_provider' => $atlasDecision->operator_requested_provider,
                'requested_provider' => $atlasDecision->requested_provider,
                'was_overridden' => (bool) $atlasDecision->was_overridden,
                'confidence_score' => $atlasDecision->confidence_score,
                'reason' => $atlasDecision->reason,
                'signals' => Metadata::forResponse($atlasDecision->signals),
                'candidates' => Metadata::listForResponse($atlasDecision->candidates),
                'constraints' => Metadata::forResponse($atlasDecision->constraints),
                'metrics_snapshot' => Metadata::forResponse($atlasDecision->metrics_snapshot),
                'task_profile' => Metadata::forResponse($atlasDecision->task_profile),
                'execution_graph' => Metadata::forResponse($atlasDecision->execution_graph),
            ]);
        }

        if (! $routerDecision) {
            return $metadataReceipt === [] ? null : Metadata::forResponse($metadataReceipt);
        }

        return array_merge(Metadata::forResponse($metadataReceipt), [
            'decision_id' => $routerDecision->id,
            'trace_id' => $this->id,
            'decision_mode' => data_get($routerDecision->signals, 'decision_mode') ?: data_get($metadataReceipt, 'decision_mode') ?: 'atlas_decide',
            'selected_provider' => $routerDecision->selected_provider,
            'fallback_provider' => $routerDecision->fallback_provider,
            'operator_requested_provider' => data_get($routerDecision->signals, 'operator_requested_provider') ?: data_get($metadataReceipt, 'operator_requested_provider') ?: 'auto',
            'requested_provider' => data_get($routerDecision->signals, 'requested_provider') ?: data_get($metadataReceipt, 'requested_provider'),
            'was_overridden' => (bool) $routerDecision->was_overridden,
            'reason' => $routerDecision->reason,
            'signals' => Metadata::forResponse($routerDecision->signals),
        ]);
    }

    /**
     * Slice atlas.dev_runtime.v1 — quando a interação passou pelo
     * AtlasDevRuntimeService o slice fica em job.payload.atlas_dev_runtime e
     * descreve flow, workspace, decision_mode, expected_artifacts e estado
     * do Open Brain para o cliente (mobile/desktop) renderizar.
     *
     * @return array<string,mixed>|null
     */
    private function atlasDevRuntimeForResponse(): ?array
    {
        $slice = $this->atlasDevRuntimeFromJobs();
        if (! is_array($slice)) {
            $slice = data_get($this->metadata, 'atlas_dev_runtime');
        }

        if (! is_array($slice) || $slice === []) {
            return null;
        }

        $openBrainStatus = is_string(data_get($this->metadata, 'open_brain_injection.status'))
            ? (string) data_get($this->metadata, 'open_brain_injection.status')
            : null;
        if ($openBrainStatus !== null) {
            $slice['open_brain_status'] = $openBrainStatus;
        }

        return Metadata::forResponse($slice);
    }

    /**
     * Canonical Atlas AI Hyperflow / RouterRuntime envelope produced by
     * {@see AtlasHyperflowEntryService}. The
     * envelope is persisted in `trace.metadata.hyperflow_runtime` (gateway)
     * AND mirrored in `job.payload.hyperflow_runtime` (worker payload), so
     * the Desktop surface can consume it from either side without depending
     * on the legacy `atlas_ai_router` shape.
     *
     * @return array<string,mixed>|null
     */
    private function hyperflowRuntimeForResponse(): ?array
    {
        $slice = $this->hyperflowRuntimeFromJobs();
        if (! is_array($slice)) {
            $slice = data_get($this->metadata, 'hyperflow_runtime');
        }

        if (! is_array($slice) || $slice === []) {
            return null;
        }

        return Metadata::forResponse($slice);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function hyperflowRuntimeFromJobs(): ?array
    {
        if ($this->resource->relationLoaded('job') && $this->job) {
            $slice = data_get($this->job->payload, 'hyperflow_runtime');
            if (is_array($slice) && $slice !== []) {
                return $slice;
            }
        }

        if ($this->resource->relationLoaded('jobs') && $this->jobs) {
            foreach ($this->jobs as $job) {
                $slice = data_get($job->payload, 'hyperflow_runtime');
                if (is_array($slice) && $slice !== []) {
                    return $slice;
                }
            }
        }

        return null;
    }

    /**
     * Flat projection of the canonical Hyperflow envelope, shaped to the
     * Desktop's TypeScript contract `AtlasAiHyperflowTrace` so the surface
     * never has to walk into the rich nested `hyperflow_runtime` keys. This
     * is intentionally derived (not stored) — the rich envelope remains the
     * source of truth; the flat slice is presentational.
     *
     * @return array<string,mixed>|null
     */
    private function hyperflowFlatForResponse(): ?array
    {
        $rich = $this->hyperflowRuntimeForResponse();
        if (! is_array($rich) || $rich === []) {
            return null;
        }

        // Degraded envelope (router_runtime tables absent / pipeline threw):
        // still expose a shape the Desktop can render so the UI doesn't fall
        // back to legacy local inference.
        if (($rich['status'] ?? null) === 'degraded') {
            return [
                'schema_version' => $rich['schema_version'] ?? null,
                'intent' => null,
                'domain_id' => null,
                'flow_id' => null,
                'runtime_mode' => null,
                'confidence' => null,
                'policy_refs' => [],
                'evidence_refs' => [],
                'decision_receipt_id' => null,
                'decision_receipt_hash' => null,
                'dispatch_status' => null,
                'handoff_target' => null,
                'handoff_reason' => null,
                'fallback_flows' => [],
                'fallback_provider' => null,
                'fallback_reason' => $this->resolveFallbackReason($rich),
                'router_was_overridden' => false,
                'flow_status' => 'degraded',
                'reasons' => array_values(array_filter([$rich['error'] ?? null], 'is_string')),
            ];
        }

        $requiredGates = is_array($rich['required_gates'] ?? null) ? $rich['required_gates'] : [];
        $policyRefs = array_values(array_filter($requiredGates, static fn ($g): bool => is_string($g) && str_starts_with($g, 'policy')));
        $evidenceRefs = array_values(array_filter($requiredGates, static fn ($g): bool => is_string($g) && str_starts_with($g, 'evidence')));

        $intentType = data_get($rich, 'intent.type');
        $intentConfidence = data_get($rich, 'intent.confidence');
        $routingConfidence = $rich['routing_confidence'] ?? null;

        $handoff = is_array($rich['handoff_target'] ?? null) ? $rich['handoff_target'] : null;
        $handoffTargetString = $handoff !== null
            ? (string) ($handoff['kind'] ?? $handoff['flow_id'] ?? '')
            : null;
        $handoffReasonString = $handoff !== null
            ? (string) ($handoff['reason'] ?? '')
            : null;

        $reasonReasons = data_get($rich, 'router_decision.reason.reasons');
        $reasons = is_array($reasonReasons)
            ? array_values(array_filter($reasonReasons, 'is_string'))
            : [];

        $fallbackFlows = array_values(array_filter(
            (array) ($rich['fallback_flows'] ?? []),
            static fn ($f): bool => is_string($f) && $f !== '',
        ));
        $fallbackProvider = $this->resource->relationLoaded('routerDecision') && $this->routerDecision
            ? $this->routerDecision->fallback_provider
            : null;
        $fallbackReason = $this->resolveFallbackReason($rich);
        $wasOverridden = $this->resource->relationLoaded('routerDecision') && $this->routerDecision
            ? (bool) $this->routerDecision->was_overridden
            : false;

        $policyRequired = (bool) ($rich['policy_required'] ?? false);
        $evidenceRequired = (bool) ($rich['evidence_required'] ?? false);
        $flowStatus = $this->resolveFlowStatus($policyRequired, $evidenceRequired, $policyRefs, $evidenceRefs);

        return [
            'schema_version' => $rich['schema_version'] ?? null,
            'intent' => is_string($intentType) && $intentType !== '' ? $intentType : null,
            'domain_id' => isset($rich['primary_domain']) && is_string($rich['primary_domain']) && $rich['primary_domain'] !== ''
                ? $rich['primary_domain']
                : null,
            'flow_id' => isset($rich['flow_id']) && is_string($rich['flow_id']) && $rich['flow_id'] !== ''
                ? $rich['flow_id']
                : null,
            'runtime_mode' => isset($rich['runtime_mode']) && is_string($rich['runtime_mode']) && $rich['runtime_mode'] !== ''
                ? $rich['runtime_mode']
                : null,
            'confidence' => is_numeric($routingConfidence)
                ? (float) $routingConfidence
                : (is_numeric($intentConfidence) ? (float) $intentConfidence : null),
            'policy_refs' => $policyRefs,
            'evidence_refs' => $evidenceRefs,
            'decision_receipt_id' => data_get($rich, 'decision_receipt.runtime_dispatch_receipt.id')
                ?? data_get($rich, 'decision_receipt.router_decision_receipt.id'),
            'decision_receipt_hash' => data_get($rich, 'decision_receipt.runtime_dispatch_receipt.receipt_hash')
                ?? data_get($rich, 'decision_receipt.router_decision_receipt.receipt_hash'),
            'dispatch_status' => isset($rich['dispatch_status']) && is_string($rich['dispatch_status'])
                ? $rich['dispatch_status']
                : null,
            'handoff_target' => $handoffTargetString !== '' ? $handoffTargetString : null,
            'handoff_reason' => $handoffReasonString !== '' ? $handoffReasonString : null,
            'fallback_flows' => $fallbackFlows,
            'fallback_provider' => $fallbackProvider,
            'fallback_reason' => $fallbackReason,
            'router_was_overridden' => $wasOverridden,
            'flow_status' => $flowStatus,
            'reasons' => $reasons,
        ];
    }

    /**
     * Resolve `fallback_reason` so the Desktop never sees a silent fallback.
     *
     * Sources, in priority order:
     *   1. explicit `fallback_reason` already in the envelope;
     *   2. degraded envelope `error` field — pipeline crashed; surface verbatim;
     *   3. dispatch blockers — runtime dispatch refused; list first reason;
     *   4. router was_overridden — surface `manual_operator_override`.
     *
     * Returns `null` only when there genuinely is no fallback signal.
     *
     * @param  array<string,mixed>  $rich
     */
    private function resolveFallbackReason(array $rich): ?string
    {
        if (isset($rich['fallback_reason']) && is_string($rich['fallback_reason']) && $rich['fallback_reason'] !== '') {
            return $rich['fallback_reason'];
        }
        if (($rich['status'] ?? null) === 'degraded' && is_string($rich['error'] ?? null) && $rich['error'] !== '') {
            return 'degraded_envelope:'.$rich['error'];
        }
        $blockers = data_get($rich, 'dispatch.blockers');
        if (is_array($blockers) && $blockers !== []) {
            $first = $blockers[0];
            $label = is_array($first) ? ($first['reason'] ?? $first['kind'] ?? null) : (is_string($first) ? $first : null);
            if (is_string($label) && $label !== '') {
                return 'dispatch_blocker:'.$label;
            }
        }
        if ($this->resource->relationLoaded('routerDecision')
            && $this->routerDecision
            && $this->routerDecision->was_overridden) {
            return 'manual_operator_override';
        }

        return null;
    }

    /**
     * Resolve `flow_status` honestly so Desktop renders partial/degraded
     * states instead of false success:
     *   - `success`              — flow declared no gates OR all required
     *                              gates have refs.
     *   - `partial_no_policy`    — policy_required=true but policy_refs=[].
     *   - `partial_no_evidence`  — evidence_required=true but evidence_refs=[].
     *   - `partial_no_gates`     — both required and both missing.
     *
     * @param  array<int,string>  $policyRefs
     * @param  array<int,string>  $evidenceRefs
     */
    private function resolveFlowStatus(bool $policyRequired, bool $evidenceRequired, array $policyRefs, array $evidenceRefs): string
    {
        $policyMissing = $policyRequired && $policyRefs === [];
        $evidenceMissing = $evidenceRequired && $evidenceRefs === [];

        if ($policyMissing && $evidenceMissing) {
            return 'partial_no_gates';
        }
        if ($policyMissing) {
            return 'partial_no_policy';
        }
        if ($evidenceMissing) {
            return 'partial_no_evidence';
        }

        return 'success';
    }

    /**
     * @return array<string,mixed>|null
     */
    private function atlasDevRuntimeFromJobs(): ?array
    {
        if ($this->resource->relationLoaded('job') && $this->job) {
            $slice = data_get($this->job->payload, 'atlas_dev_runtime');
            if (is_array($slice) && $slice !== []) {
                return $slice;
            }
        }

        if ($this->resource->relationLoaded('jobs') && $this->jobs) {
            foreach ($this->jobs as $job) {
                $slice = data_get($job->payload, 'atlas_dev_runtime');
                if (is_array($slice) && $slice !== []) {
                    return $slice;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function specialistFlowRuntimeForResponse(): ?array
    {
        $slice = $this->specialistFlowRuntimeFromJobs();
        if (! is_array($slice)) {
            $slice = data_get($this->metadata, 'specialist_flow_runtime');
        }

        if (! is_array($slice) || $slice === []) {
            return null;
        }

        return Metadata::forResponse($slice);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function specialistFlowRuntimeFromJobs(): ?array
    {
        if ($this->resource->relationLoaded('job') && $this->job) {
            $slice = data_get($this->job->payload, 'specialist_flow_runtime');
            if (is_array($slice) && $slice !== []) {
                return $slice;
            }
        }

        if ($this->resource->relationLoaded('jobs') && $this->jobs) {
            foreach ($this->jobs as $job) {
                $slice = data_get($job->payload, 'specialist_flow_runtime');
                if (is_array($slice) && $slice !== []) {
                    return $slice;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function specialistFlowExecutionForResponse(): ?array
    {
        $execution = $this->specialistFlowExecutionFromJobs();
        if (! is_array($execution)) {
            $execution = data_get($this->metadata, 'specialist_flow_execution');
        }

        if (! is_array($execution) || $execution === []) {
            return null;
        }

        return Metadata::forResponse($execution);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function specialistFlowExecutionFromJobs(): ?array
    {
        if ($this->resource->relationLoaded('job') && $this->job) {
            $execution = data_get($this->job->payload, 'specialist_flow_execution');
            if (is_array($execution) && $execution !== []) {
                return $execution;
            }
        }

        if ($this->resource->relationLoaded('jobs') && $this->jobs) {
            foreach ($this->jobs as $job) {
                $execution = data_get($job->payload, 'specialist_flow_execution');
                if (is_array($execution) && $execution !== []) {
                    return $execution;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function publicAttachments(): array
    {
        $attachments = [];

        if ($this->resource->relationLoaded('job') && $this->job) {
            $attachments = [
                ...$attachments,
                ...AiAttachmentPayload::publicAttachmentsFromPayload($this->job->payload),
            ];
        }

        if ($this->resource->relationLoaded('jobs') && $this->jobs) {
            foreach ($this->jobs as $job) {
                $attachments = [
                    ...$attachments,
                    ...AiAttachmentPayload::publicAttachmentsFromPayload($job->payload),
                ];
            }
        }

        $byId = [];
        foreach ($attachments as $attachment) {
            $id = is_string($attachment['id'] ?? null) ? $attachment['id'] : null;
            if (! $id || isset($byId[$id])) {
                continue;
            }

            $attachment['content_url'] = "/ai/interactions/{$this->id}/attachments/{$id}/content";
            $renderedPageCount = is_numeric($attachment['pdf_rendered_page_count'] ?? null)
                ? (int) $attachment['pdf_rendered_page_count']
                : (is_numeric($attachment['office_rendered_page_count'] ?? null)
                    ? (int) $attachment['office_rendered_page_count']
                    : 0);
            if ($renderedPageCount > 0) {
                $attachment['preview_pages'] = collect(range(1, min($renderedPageCount, 12)))
                    ->map(fn (int $page): array => [
                        'page' => $page,
                        'url' => "/ai/interactions/{$this->id}/attachments/{$id}/pages/{$page}",
                    ])
                    ->values()
                    ->all();
            }

            $byId[$id] = $attachment;
        }

        return array_values($byId);
    }
}
