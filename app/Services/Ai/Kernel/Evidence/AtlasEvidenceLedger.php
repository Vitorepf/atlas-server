<?php

namespace App\Services\Ai\Kernel\Evidence;

use App\Models\AiQualityEvaluation;
use App\Models\AiTrace;
use App\Models\AtlasLedgerEvent;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Kernel\Envelope\OperationEnvelope;
use App\Services\Ai\Kernel\Failure\FailureClassifier;
use App\Services\Ai\Kernel\Failure\FailureHandlerRegistry;
use App\Services\Ai\Kernel\Repair\RepairDecision;
use App\Services\Ai\Kernel\Repair\RepairResult;
use App\Services\Ai\Kernel\Slo\KernelSloAssessment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class AtlasEvidenceLedger
{
    public const SCHEMA_VERSION = 'atlas.ledger_event.v1';

    public function __construct(
        private readonly FailureClassifier $failureClassifier,
        private readonly FailureHandlerRegistry $failureHandlers,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $context
     */
    public function record(
        LedgerEventType $type,
        array $payload,
        array $context = [],
    ): ?AtlasLedgerEvent {
        if (! Schema::hasTable('atlas_ledger_events')) {
            return null;
        }

        $payload = $this->canonicalize($payload);
        $occurredAt = isset($context['occurred_at'])
            ? CarbonImmutable::parse($context['occurred_at'])
            : CarbonImmutable::now();

        return AtlasLedgerEvent::query()->create([
            'event_id' => $this->string($context['event_id'] ?? (string) Str::ulid(), 32),
            'schema_version' => self::SCHEMA_VERSION,
            'tenant_id' => $this->string($context['tenant_id'] ?? data_get($payload, 'operator.tenant_id', 'default'), 120),
            'operator_id' => $this->string($context['operator_id'] ?? data_get($payload, 'operator.operator_id', 'system'), 120),
            'envelope_id' => $this->string($context['envelope_id'] ?? data_get($payload, 'envelope_id', 'unknown'), 80),
            'receipt_id' => $this->nullableString($context['receipt_id'] ?? data_get($payload, 'receipt_id'), 80),
            'trace_id' => $this->nullableString($context['trace_id'] ?? data_get($payload, 'trace_id'), 80),
            'correlation_id' => $this->string($context['correlation_id'] ?? data_get($payload, 'correlation_id', data_get($payload, 'envelope_id', (string) Str::ulid())), 120),
            'causation_id' => $this->nullableString($context['causation_id'] ?? null, 80),
            'event_type' => $type->value,
            'emitter_stage' => $this->string($context['emitter_stage'] ?? 'atlas.kernel', 120),
            'emitter_version' => $this->string($context['emitter_version'] ?? 'v1', 80),
            'payload' => $payload,
            'payload_hash' => $this->payloadHash($payload),
            'occurred_at' => $occurredAt,
        ]);
    }

    public function recordEnvelopeCreated(OperationEnvelope $envelope): ?AtlasLedgerEvent
    {
        return $this->record(LedgerEventType::EnvelopeCreated, [
            'envelope_id' => $envelope->envelopeId,
            'parent_envelope_id' => $envelope->parentEnvelopeId,
            'schema_version' => $envelope->schemaVersion,
            'trace_id' => $envelope->audit->traceId,
            'chain_hash' => $envelope->audit->chainHash,
            'input_hash' => $envelope->input->inputHash,
            'operator' => [
                'tenant_id' => $envelope->operator->tenantId,
                'operator_id' => $envelope->operator->operatorId,
            ],
            'origin' => [
                'surface_id' => $envelope->origin->surfaceId,
                'surface_version' => $envelope->origin->surfaceVersion,
                'session_id' => $envelope->origin->sessionId,
            ],
        ], [
            'tenant_id' => $envelope->operator->tenantId,
            'operator_id' => $envelope->operator->operatorId,
            'envelope_id' => $envelope->envelopeId,
            'trace_id' => $envelope->audit->traceId,
            'correlation_id' => $envelope->audit->traceId,
            'emitter_stage' => 'atlas.envelope_factory',
            'emitter_version' => OperationEnvelope::SCHEMA_VERSION,
        ]);
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @param  array<string,mixed>  $context
     * @return array{envelope_created:null,decision_issued:?AtlasLedgerEvent}
     */
    public function recordDecisionIssued(array $receipt, array $context = []): array
    {
        $ledgerContext = [
            'tenant_id' => $context['tenant_id'] ?? data_get($receipt, 'metadata.tenant_id', 'default'),
            'operator_id' => $context['operator_id'] ?? data_get($receipt, 'metadata.operator_id', 'system'),
            'envelope_id' => $receipt['envelope_id'] ?? 'unknown',
            'receipt_id' => $receipt['receipt_id'] ?? null,
            'trace_id' => $context['trace_id'] ?? null,
            'correlation_id' => $context['correlation_id'] ?? ($receipt['envelope_id'] ?? null),
            'emitter_stage' => $context['emitter_stage'] ?? 'atlas.decide',
            'emitter_version' => $context['emitter_version'] ?? 'atlas-decide-v2',
        ];

        $decisionEvent = $this->record(LedgerEventType::DecisionIssued, [
            'envelope_id' => $receipt['envelope_id'] ?? null,
            'receipt_id' => $receipt['receipt_id'] ?? null,
            'schema_version' => $receipt['schema_version'] ?? null,
            'dry_run' => (bool) ($receipt['dry_run'] ?? false),
            'signed_by' => $receipt['signed_by'] ?? null,
            'domain' => $receipt['domain'] ?? null,
            'flow' => $receipt['flow'] ?? null,
            'risk' => $receipt['risk'] ?? null,
            'provider_selection' => $receipt['provider_selection'] ?? [],
            'budgets' => $receipt['budgets'] ?? [],
            'required_gates' => $receipt['required_gates'] ?? [],
            'required_evidence' => $receipt['required_evidence'] ?? [],
            'repair_policy' => $receipt['repair_policy'] ?? [],
            'inputs_hash' => $receipt['inputs_hash'] ?? null,
            'receipt_hash' => $receipt['receipt_hash'] ?? null,
            'parent_receipt_id' => $receipt['parent_receipt_id'] ?? null,
            'parent_chain_hash' => $receipt['parent_chain_hash'] ?? data_get($receipt, 'metadata.parent_chain_hash'),
            'chain_hash' => $receipt['chain_hash'] ?? null,
            'issued_at' => $receipt['issued_at'] ?? null,
            'expires_at' => $receipt['expires_at'] ?? null,
        ], $ledgerContext);

        return [
            'envelope_created' => null,
            'decision_issued' => $decisionEvent,
        ];
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $context
     */
    public function recordKernelPipelineAccepted(array $plan, array $context = []): ?AtlasLedgerEvent
    {
        return $this->recordKernelPipelineContract(
            type: LedgerEventType::KernelPipelineAccepted,
            plan: $plan,
            status: 'accepted',
            violations: [],
            context: $context,
        );
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<int,string>  $violations
     * @param  array<string,mixed>  $context
     */
    public function recordKernelPipelineRejected(array $plan, array $violations, array $context = []): ?AtlasLedgerEvent
    {
        return $this->recordKernelPipelineContract(
            type: LedgerEventType::KernelPipelineRejected,
            plan: $plan,
            status: 'rejected',
            violations: $violations,
            context: $context,
        );
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<int,string>  $violations
     * @param  array<string,mixed>  $context
     */
    private function recordKernelPipelineContract(
        LedgerEventType $type,
        array $plan,
        string $status,
        array $violations,
        array $context = [],
    ): ?AtlasLedgerEvent {
        $pipelineId = $this->string($plan['pipeline_id'] ?? data_get($context, 'pipeline_id', 'kernel_pipeline_unknown'), 120);
        $surfaceId = $this->nullableString(data_get($plan, 'input.surface_id') ?? data_get($plan, 'surface_binding.surface'), 120);
        $flow = $this->nullableString(data_get($plan, 'input.safe_hints.flow') ?? data_get($plan, 'surface_binding.flow'), 120);
        $stageOrder = $this->kernelPipelineStageOrder($plan);

        return $this->record($type, [
            'status' => $status,
            'violations' => array_values(array_map('strval', $violations)),
            'pipeline' => [
                'pipeline_id' => $pipelineId,
                'schema_version' => $plan['schema_version'] ?? null,
                'mode' => $plan['mode'] ?? null,
                'status' => $plan['status'] ?? null,
                'canonical_flow_hash' => $plan['canonical_flow_hash'] ?? null,
                'stage_order' => $stageOrder,
                'stage_count' => $plan['stage_count'] ?? count($stageOrder),
                'provider_execution_allowed' => (bool) ($plan['provider_execution_allowed'] ?? false),
                'runtime_execution_allowed' => (bool) ($plan['runtime_execution_allowed'] ?? false),
                'execution_guards' => is_array($plan['execution_guards'] ?? null) ? $plan['execution_guards'] : [],
            ],
            'surface' => [
                'surface_id' => $surfaceId,
                'binding_surface' => data_get($plan, 'surface_binding.surface'),
                'command' => data_get($plan, 'surface_binding.command'),
                'input_mode' => data_get($plan, 'surface_binding.input_mode'),
            ],
            'surface_contract' => [
                'required' => (bool) data_get($context, 'surface_contract.required', false),
                'source' => $this->nullableString(data_get($context, 'surface_contract.source'), 120),
                'surface_must_not_decide' => (bool) data_get($context, 'surface_contract.surface_must_not_decide', false),
                'provider_execution_blocked_until_runtime_migration' => (bool) data_get($context, 'surface_contract.provider_execution_blocked_until_runtime_migration', false),
                'runtime_execution_blocked_until_runtime_migration' => (bool) data_get($context, 'surface_contract.runtime_execution_blocked_until_runtime_migration', false),
            ],
            'routing' => [
                'domain' => data_get($plan, 'input.safe_hints.domain'),
                'flow' => $flow,
                'mode' => data_get($plan, 'input.safe_hints.mode'),
                'runtime' => data_get($plan, 'input.safe_hints.runtime'),
            ],
            'input' => [
                'primary_text_hash' => data_get($plan, 'input.primary_text_hash'),
                'input_fingerprint' => data_get($plan, 'input.input_fingerprint'),
                'hints_hash' => data_get($plan, 'input.hints_hash'),
                'metadata_keys' => data_get($plan, 'input.metadata_keys', []),
            ],
        ], [
            'tenant_id' => $context['tenant_id'] ?? data_get($plan, 'input.tenant_id', 'default'),
            'operator_id' => $context['operator_id'] ?? data_get($plan, 'input.operator_id', 'system'),
            'envelope_id' => $context['envelope_id'] ?? 'kernel_pipeline:'.$pipelineId,
            'receipt_id' => $context['receipt_id'] ?? null,
            'trace_id' => $context['trace_id'] ?? null,
            'correlation_id' => $context['correlation_id'] ?? $pipelineId,
            'causation_id' => $context['causation_id'] ?? null,
            'emitter_stage' => $context['emitter_stage'] ?? 'atlas.kernel_pipeline_guard',
            'emitter_version' => $context['emitter_version'] ?? 'atlas.kernel_pipeline_guard.v1',
        ]);
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<int,string>
     */
    private function kernelPipelineStageOrder(array $plan): array
    {
        if (is_array($plan['stage_order'] ?? null)) {
            return array_values(array_filter(array_map(
                fn (mixed $stage): ?string => is_scalar($stage) ? trim((string) $stage) : null,
                $plan['stage_order'],
            )));
        }

        return array_values(array_filter(array_map(
            fn (mixed $stage): ?string => is_scalar(data_get($stage, 'stage')) ? trim((string) data_get($stage, 'stage')) : null,
            (array) ($plan['stages'] ?? $plan['stage_results'] ?? []),
        )));
    }

    /**
     * @param  Throwable|array<string,mixed>|string  $failure
     * @param  array<string,mixed>  $context
     * @return array{classification:array<string,mixed>,handling:array<string,mixed>,event:?AtlasLedgerEvent}
     */
    public function recordFailure(Throwable|array|string $failure, array $context = []): array
    {
        $message = is_string($context['message'] ?? null) ? $context['message'] : null;
        $classification = $this->failureClassifier->classify($failure, $message);
        $classificationPayload = $classification->toArray();
        $handling = $this->failureHandlers
            ->handlerFor($classification->domain)
            ->handle($classificationPayload, $context);

        $eventType = match (true) {
            (bool) ($handling['requires_human_review'] ?? false) => LedgerEventType::OperationNeedsReview,
            (bool) ($handling['retryable'] ?? false) => LedgerEventType::OperationFailed,
            default => LedgerEventType::OperationBlocked,
        };

        $event = $this->record($eventType, [
            'classification' => $classificationPayload,
            'handling' => $handling,
            'failure' => $this->failurePayload($failure, $message),
        ], $context + [
            'emitter_stage' => 'atlas.failure_classifier',
            'emitter_version' => 'atlas.failure.v1',
        ]);

        return [
            'classification' => $classificationPayload,
            'handling' => $handling,
            'event' => $event,
        ];
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $context
     */
    public function recordPolicyContractBlocked(
        string $contract,
        array $receipt,
        array $options = [],
        array $context = [],
    ): ?AtlasLedgerEvent {
        $status = $this->string($receipt['status'] ?? 'unknown', 120);
        $payload = [
            'policy_contract' => $contract,
            'status' => $status,
            'violation_code' => $contract.'.'.$status,
            'receipt' => $receipt,
            'surface_id' => data_get($options, 'payload.surface_id', data_get($options, 'source')),
            'provider' => $options['provider'] ?? data_get($options, 'payload.selected_provider'),
            'model' => $options['model'] ?? null,
            'client_id' => $options['client_id'] ?? null,
            'session_id' => data_get($options, 'payload.session_id'),
            'thread_id' => data_get($options, 'payload.thread_id'),
        ];

        return $this->record(LedgerEventType::OperationBlocked, $payload, [
            'tenant_id' => $context['tenant_id'] ?? data_get($options, 'payload.tenant_id', 'default'),
            'operator_id' => $context['operator_id'] ?? data_get($options, 'payload.operator_id', 'system'),
            'envelope_id' => $context['envelope_id']
                ?? data_get($options, 'payload.decision_receipt.envelope_id')
                ?? data_get($options, 'payload.decision_receipt.receipt_v2.envelope_id')
                ?? 'policy_contract_pre_trace',
            'receipt_id' => $context['receipt_id']
                ?? data_get($options, 'payload.decision_receipt.receipt_id')
                ?? data_get($options, 'payload.decision_receipt.receipt_v2.receipt_id'),
            'trace_id' => $context['trace_id'] ?? data_get($options, 'payload.trace_id'),
            'correlation_id' => $context['correlation_id']
                ?? $options['client_id']
                ?? data_get($options, 'payload.trace_id')
                ?? data_get($options, 'payload.decision_receipt.envelope_id')
                ?? 'policy_contract_pre_trace',
            'emitter_stage' => 'atlas.policy_contract',
            'emitter_version' => 'atlas.policy_contract.v1',
        ]);
    }

    /**
     * @param  array<string,mixed>  $decision
     * @param  array<string,mixed>  $context
     */
    public function recordProviderMemoryBlocked(
        AtlasMemoryEntry $entry,
        array $decision,
        string $target,
        array $context = [],
    ): ?AtlasLedgerEvent {
        $payload = [
            'policy_contract' => 'memory.provider_projection',
            'status' => 'provider_memory_blocked',
            'violation_code' => 'memory.provider_projection.'.($decision['reason'] ?? 'not_provider_safe'),
            'target' => $target,
            'memory_entry' => [
                'id' => $entry->id ? (string) $entry->id : null,
                'memory_type' => $entry->memory_type,
                'scope_type' => $entry->scope_type,
                'scope_id' => $entry->scope_id,
                'source_type' => $entry->source_type,
                'source_id' => $entry->source_id,
            ],
            'privacy' => [
                'class' => $decision['privacy_class'] ?? null,
                'external_ai_allowed' => $decision['external_ai_allowed'] ?? null,
                'metadata_external_ai_allowed' => $decision['metadata_external_ai_allowed'] ?? null,
                'reason' => $decision['reason'] ?? 'not_provider_safe',
            ],
        ];

        return $this->record(LedgerEventType::OperationBlocked, $payload, [
            'tenant_id' => $context['tenant_id'] ?? data_get($entry->metadata, 'operator.tenant_id', 'default'),
            'operator_id' => $context['operator_id'] ?? data_get($entry->metadata, 'operator.operator_id', 'system'),
            'envelope_id' => $context['envelope_id'] ?? data_get($entry->metadata, 'envelope_id', 'memory_provider_projection'),
            'receipt_id' => $context['receipt_id'] ?? data_get($entry->metadata, 'receipt_id'),
            'trace_id' => $context['trace_id'] ?? $entry->trace_id,
            'correlation_id' => $context['correlation_id'] ?? $entry->trace_id ?? $entry->session_id ?? 'memory_provider_projection',
            'emitter_stage' => 'atlas.memory_provider_privacy',
            'emitter_version' => 'atlas.memory_provider_privacy.v1',
        ]);
    }

    /**
     * @param  array<string,mixed>  $context
     */
    public function recordRepairDecision(RepairDecision $decision, array $context = []): ?AtlasLedgerEvent
    {
        $payload = $decision->evidencePayload + [
            'decision' => $decision->toArray(),
            'repair_executed' => false,
        ];

        return $this->record(LedgerEventType::RepairInitiated, $payload, [
            'tenant_id' => $context['tenant_id'] ?? data_get($payload, 'operator.tenant_id', 'default'),
            'operator_id' => $context['operator_id'] ?? data_get($payload, 'operator.operator_id', 'system'),
            'envelope_id' => $context['envelope_id'] ?? data_get($payload, 'envelope_id', 'repair_decision'),
            'receipt_id' => $context['receipt_id'] ?? data_get($payload, 'receipt_id'),
            'trace_id' => $context['trace_id'] ?? data_get($payload, 'trace_id'),
            'correlation_id' => $context['correlation_id'] ?? data_get($payload, 'envelope_id', 'repair_decision'),
            'emitter_stage' => $context['emitter_stage'] ?? 'atlas.repair',
            'emitter_version' => $context['emitter_version'] ?? 'atlas.repair.v1',
        ]);
    }

    /**
     * @param  array<string,mixed>  $context
     */
    public function recordRepairResult(RepairResult $result, array $context = []): ?AtlasLedgerEvent
    {
        $payload = $result->evidencePayload + [
            'request' => $result->request->toArray(),
            'decision' => $result->decision->toArray(),
            'attempt' => $result->attempt?->toArray(),
            'repair_executed' => $result->executed,
        ];

        return $this->record(LedgerEventType::RepairCompleted, $payload, [
            'tenant_id' => $context['tenant_id'] ?? data_get($payload, 'operator.tenant_id', 'default'),
            'operator_id' => $context['operator_id'] ?? data_get($payload, 'operator.operator_id', 'system'),
            'envelope_id' => $context['envelope_id'] ?? data_get($payload, 'envelope_id', 'repair_result'),
            'receipt_id' => $context['receipt_id'] ?? data_get($payload, 'receipt_id'),
            'trace_id' => $context['trace_id'] ?? data_get($payload, 'trace_id'),
            'correlation_id' => $context['correlation_id'] ?? data_get($payload, 'envelope_id', 'repair_result'),
            'causation_id' => $context['causation_id'] ?? data_get($result->decision->evidencePayload, 'decision_hash'),
            'emitter_stage' => $context['emitter_stage'] ?? 'atlas.repair',
            'emitter_version' => $context['emitter_version'] ?? 'atlas.repair.v1',
        ]);
    }

    /**
     * @param  array<int,array<string,mixed>>  $findings
     * @param  array<string,mixed>  $context
     */
    public function recordAgentBehaviorGateEvaluation(
        AiTrace $trace,
        AiQualityEvaluation $evaluation,
        array $findings,
        array $context = [],
    ): ?AtlasLedgerEvent {
        $traceMetadata = is_array($trace->metadata) ? $trace->metadata : [];
        $evaluationMetadata = is_array($evaluation->metadata) ? $evaluation->metadata : [];

        return $this->record(LedgerEventType::GateEvaluated, [
            'gate_id' => 'atlas.agent_behavior',
            'schema_version' => 'atlas.agent_behavior.gate_evaluation.v1',
            'status' => $evaluation->status,
            'score' => $evaluation->score,
            'provider' => $trace->provider,
            'model' => $trace->model,
            'agent_slug' => $trace->agent_slug,
            'flags' => collect($evaluation->flags ?? [])->pluck('code')->filter()->values()->all(),
            'suggested_actions' => collect($evaluation->suggested_actions ?? [])->pluck('code')->filter()->values()->all(),
            'agent_behavior_findings' => array_values($findings),
            'contract_id' => data_get($findings, '0.metadata.contract_id'),
            'contract_hash' => data_get($findings, '0.metadata.contract_hash'),
            'quality_evaluation_id' => (string) $evaluation->id,
            'trace' => [
                'trace_id' => (string) $trace->id,
                'thread_id' => $trace->thread_id,
                'session_id' => $trace->session_id,
                'source_type' => $trace->source_type,
            ],
            'evidence' => [
                'task_looks_like_development' => (bool) data_get($evaluationMetadata, 'evidence.task_looks_like_development', false),
                'response_chars' => data_get($evaluationMetadata, 'evidence.response_chars'),
                'code_fence_count' => data_get($evaluationMetadata, 'evidence.code_fence_count'),
                'code_like_line_count' => data_get($evaluationMetadata, 'evidence.code_like_line_count'),
            ],
        ], [
            'tenant_id' => $context['tenant_id'] ?? data_get($traceMetadata, 'operator.tenant_id', 'default'),
            'operator_id' => $context['operator_id'] ?? data_get($traceMetadata, 'operator.operator_id', 'system'),
            'envelope_id' => $context['envelope_id']
                ?? data_get($traceMetadata, 'decision_receipt.envelope_id')
                ?? data_get($traceMetadata, 'decision_receipt.receipt_v2.envelope_id')
                ?? 'ai_trace:'.(string) $trace->id,
            'receipt_id' => $context['receipt_id']
                ?? data_get($traceMetadata, 'decision_receipt.receipt_id')
                ?? data_get($traceMetadata, 'decision_receipt.receipt_v2.receipt_id'),
            'trace_id' => $context['trace_id'] ?? (string) $trace->id,
            'correlation_id' => $context['correlation_id'] ?? (string) ($trace->thread_id ?: $trace->id),
            'causation_id' => $context['causation_id'] ?? (string) $evaluation->id,
            'emitter_stage' => 'atlas.agent_behavior_quality_gate',
            'emitter_version' => 'atlas.agent_behavior.v1',
        ]);
    }

    /**
     * @param  array<string,mixed>  $context
     */
    public function recordSloObservation(KernelSloAssessment $assessment, array $context = []): ?AtlasLedgerEvent
    {
        return $this->record(LedgerEventType::SloObserved, [
            'dimensions' => $this->sloDimensions($context),
            'slo' => $assessment->toArray(),
            'stage' => $assessment->stage,
            'status' => $assessment->status,
            'severity' => $assessment->severity,
            'violations' => $assessment->violations,
        ], [
            'tenant_id' => $context['tenant_id'] ?? 'default',
            'operator_id' => $context['operator_id'] ?? 'system',
            'envelope_id' => $context['envelope_id'] ?? 'slo_observation',
            'receipt_id' => $context['receipt_id'] ?? null,
            'trace_id' => $context['trace_id'] ?? null,
            'correlation_id' => $context['correlation_id'] ?? $context['trace_id'] ?? 'slo_observation',
            'emitter_stage' => 'atlas.slo',
            'emitter_version' => 'atlas.slo.v1',
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $context
     */
    public function recordVoiceEvent(
        LedgerEventType $type,
        array $payload,
        array $context = [],
    ): ?AtlasLedgerEvent {
        if (! str_starts_with($type->value, 'VOICE_')) {
            throw new \InvalidArgumentException('recordVoiceEvent only accepts VOICE_* ledger event types.');
        }

        $payload = $this->sanitizeVoicePayload($payload);

        return $this->record($type, [
            'schema_version' => 'atlas.voice.ledger_event.v1',
            'privacy_class' => $payload['privacy_class'] ?? 'p3_audio',
            'surface_id' => $payload['surface_id'] ?? 'voice_realtime',
            'voice' => $payload,
        ], [
            'tenant_id' => $context['tenant_id'] ?? data_get($payload, 'operator.tenant_id', 'default'),
            'operator_id' => $context['operator_id'] ?? data_get($payload, 'operator.operator_id', 'system'),
            'envelope_id' => $context['envelope_id'] ?? data_get($payload, 'envelope_id', 'voice_realtime'),
            'receipt_id' => $context['receipt_id'] ?? data_get($payload, 'receipt_id'),
            'trace_id' => $context['trace_id'] ?? data_get($payload, 'trace_id'),
            'correlation_id' => $context['correlation_id']
                ?? data_get($payload, 'session_id')
                ?? data_get($payload, 'turn_id')
                ?? data_get($payload, 'envelope_id')
                ?? 'voice_realtime',
            'causation_id' => $context['causation_id'] ?? data_get($payload, 'causation_id'),
            'emitter_stage' => $context['emitter_stage'] ?? 'atlas.voice_realtime',
            'emitter_version' => $context['emitter_version'] ?? 'atlas.voice.v1',
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function sanitizeVoicePayload(array $payload): array
    {
        $forbiddenKeys = [
            'audio',
            'audio_bytes',
            'audio_blob',
            'audio_buffer',
            'audio_raw',
            'raw_audio',
            'raw_audio_bytes',
            'pcm',
            'wav',
        ];

        foreach ($forbiddenKeys as $key) {
            unset($payload[$key]);
        }

        if (isset($payload['transcript']) && is_string($payload['transcript'])) {
            $payload['transcript_hash'] ??= hash('sha256', $payload['transcript']);
            $payload['transcript_length'] ??= mb_strlen($payload['transcript']);
            unset($payload['transcript']);
        }

        foreach (['response_text', 'synthesized_text', 'tts_text'] as $textKey) {
            if (isset($payload[$textKey]) && is_string($payload[$textKey])) {
                $hashKey = $textKey.'_hash';
                $lengthKey = $textKey.'_length';
                $payload[$hashKey] ??= hash('sha256', $payload[$textKey]);
                $payload[$lengthKey] ??= mb_strlen($payload[$textKey]);
                unset($payload[$textKey]);
            }
        }

        if (isset($payload['audio_hash']) && is_scalar($payload['audio_hash'])) {
            $payload['audio_hash'] = $this->string((string) $payload['audio_hash'], 120);
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,string>
     */
    private function sloDimensions(array $context): array
    {
        $aliases = [
            'domain' => ['domain', 'kernel_domain'],
            'flow' => ['flow', 'kernel_flow'],
            'surface_id' => ['surface_id', 'surface', 'app_surface'],
            'provider' => ['provider', 'selected_provider', 'provider_id'],
            'model' => ['model', 'selected_model'],
            'runtime' => ['runtime', 'runtime_id'],
            'tool_id' => ['tool_id', 'tool'],
            'job_id' => ['job_id'],
            'attempt_id' => ['attempt_id'],
            'worker_id' => ['worker_id'],
        ];

        $dimensions = [];
        foreach ($aliases as $target => $keys) {
            foreach ($keys as $key) {
                $value = data_get($context, $key);
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $dimensions[$target] = $this->string($value, 120);
                    break;
                }
            }
        }

        return $dimensions;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function eventsForEnvelope(string $envelopeId): array
    {
        if (! Schema::hasTable('atlas_ledger_events')) {
            return [];
        }

        return AtlasLedgerEvent::query()
            ->where('envelope_id', $envelopeId)
            ->orderBy('occurred_at')
            ->orderBy('event_id')
            ->get()
            ->map(fn (AtlasLedgerEvent $event): array => $event->toArray())
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function canonicalize(array $payload): array
    {
        ksort($payload);

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->canonicalize($value);
            }
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function payloadHash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function string(mixed $value, int $max): string
    {
        $value = trim((string) $value);

        return Str::limit($value !== '' ? $value : 'unknown', $max, '');
    }

    private function nullableString(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? Str::limit($value, $max, '') : null;
    }

    /**
     * @param  Throwable|array<string,mixed>|string  $failure
     * @return array<string,mixed>
     */
    private function failurePayload(Throwable|array|string $failure, ?string $message): array
    {
        if ($failure instanceof Throwable) {
            return [
                'source' => 'throwable',
                'class' => $failure::class,
                'code' => $failure->getCode(),
                'message' => $failure->getMessage(),
            ];
        }

        if (is_array($failure)) {
            return [
                'source' => 'payload',
                'payload' => $failure,
            ];
        }

        return [
            'source' => 'status_message',
            'status' => $failure,
            'message' => $message,
        ];
    }
}
