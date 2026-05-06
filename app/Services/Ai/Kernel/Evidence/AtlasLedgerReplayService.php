<?php

namespace App\Services\Ai\Kernel\Evidence;

use App\Models\AiInboxItem;
use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Decision\DecisionReceiptHash;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class AtlasLedgerReplayService
{
    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @return array<int,array<string,mixed>>
     */
    public function eventsForEnvelope(string $envelopeId): array
    {
        return $this->ledger->eventsForEnvelope($envelopeId);
    }

    /**
     * @return array{
     *     envelope_id:string,
     *     observation_count:int,
     *     status_counts:array<string,int>,
     *     success_count:int,
     *     failure_count:int,
     *     worst_status:string|null,
     *     worst_severity:string|null,
     *     dimensions:array<string,array<string,int>>,
     *     stages:array<string,array<string,mixed>>
     * }
     */
    public function sloReportForEnvelope(string $envelopeId): array
    {
        $observations = collect($this->eventsForEnvelope($envelopeId))
            ->filter(fn (array $event): bool => ($event['event_type'] ?? null) === LedgerEventType::SloObserved->value)
            ->map(fn (array $event): array => $this->sloObservationFromEvent($event))
            ->values();

        return [
            'envelope_id' => $envelopeId,
            ...$this->sloObservationSummary($observations),
        ];
    }

    /**
     * @return array{
     *     envelope_id:string,
     *     repair_event_count:int,
     *     initiated_count:int,
     *     completed_count:int,
     *     executed_count:int,
     *     status_counts:array<string,int>,
     *     strategy_counts:array<string,int>,
     *     reason_counts:array<string,int>,
     *     latest_status:string|null,
     *     latest_strategy:string|null,
     *     requires_human_review:bool,
     *     events:array<int,array<string,mixed>>
     * }
     */
    public function repairReportForEnvelope(string $envelopeId): array
    {
        $events = collect($this->eventsForEnvelope($envelopeId))
            ->filter(fn (array $event): bool => in_array($event['event_type'] ?? null, [
                LedgerEventType::RepairInitiated->value,
                LedgerEventType::RepairCompleted->value,
            ], true))
            ->map(fn (array $event): array => $this->repairEventFromEvent($event))
            ->values();

        return [
            'envelope_id' => $envelopeId,
            ...$this->repairEventSummary($events),
        ];
    }

    /**
     * @return array{
     *     envelope_id:string,
     *     kernel_pipeline_event_count:int,
     *     accepted_count:int,
     *     rejected_count:int,
     *     status_counts:array<string,int>,
     *     surface_counts:array<string,int>,
     *     emitter_stage_counts:array<string,int>,
     *     flow_counts:array<string,int>,
     *     input_mode_counts:array<string,int>,
     *     violation_counts:array<string,int>,
     *     latest_status:string|null,
     *     has_rejections:bool,
     *     events:array<int,array<string,mixed>>
     * }
     */
    public function kernelPipelineReportForEnvelope(string $envelopeId): array
    {
        $events = collect($this->eventsForEnvelope($envelopeId))
            ->filter(fn (array $event): bool => in_array($event['event_type'] ?? null, [
                LedgerEventType::KernelPipelineAccepted->value,
                LedgerEventType::KernelPipelineRejected->value,
            ], true))
            ->map(fn (array $event): array => $this->kernelPipelineEventFromEvent($event))
            ->values();

        return [
            'envelope_id' => $envelopeId,
            ...$this->kernelPipelineEventSummary($events),
        ];
    }

    /**
     * @return array{
     *     envelope_id:string,
     *     decision_event_count:int,
     *     valid_receipt_hash_count:int,
     *     valid_chain_hash_count:int,
     *     invalid_count:int,
     *     latest_receipt_id:string|null,
     *     latest_chain_hash:string|null,
     *     review_signal:array<string,mixed>,
     *     events:array<int,array<string,mixed>>
     * }
     */
    public function decisionReceiptReportForEnvelope(string $envelopeId): array
    {
        $events = collect($this->eventsForEnvelope($envelopeId))
            ->filter(fn (array $event): bool => ($event['event_type'] ?? null) === LedgerEventType::DecisionIssued->value)
            ->map(fn (array $event): array => $this->decisionReceiptEventFromEvent($event))
            ->values();

        return [
            'envelope_id' => $envelopeId,
            ...$this->decisionReceiptEventSummary($events),
        ];
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<string,mixed>
     */
    public function kernelPipelineReportForWindow(CarbonInterface $since, ?CarbonInterface $until = null, array $filters = []): array
    {
        $until ??= now();
        $filters = $this->normalizedKernelPipelineFilters($filters);

        if (! Schema::hasTable('atlas_ledger_events')) {
            return [
                'available' => false,
                'window' => [
                    'since' => $since->toJSON(),
                    'until' => $until->toJSON(),
                ],
                'filters' => $filters,
                'envelope_count' => 0,
                ...$this->kernelPipelineEventSummary(collect()),
                'recent_events' => [],
            ];
        }

        $events = AtlasLedgerEvent::query()
            ->whereIn('event_type', [
                LedgerEventType::KernelPipelineAccepted->value,
                LedgerEventType::KernelPipelineRejected->value,
            ])
            ->whereBetween('occurred_at', [$since, $until])
            ->orderBy('occurred_at')
            ->orderBy('event_id')
            ->get()
            ->map(fn (AtlasLedgerEvent $event): array => $this->kernelPipelineEventFromEvent($event->toArray()))
            ->filter(fn (array $event): bool => $this->matchesKernelPipelineFilters($event, $filters))
            ->values();
        $summary = $this->kernelPipelineEventSummary($events);

        return [
            'available' => true,
            'window' => [
                'since' => $since->toJSON(),
                'until' => $until->toJSON(),
            ],
            'filters' => $filters,
            'envelope_count' => $events->pluck('envelope_id')->filter()->unique()->count(),
            ...array_diff_key($summary, ['events' => true]),
            'recent_events' => $events
                ->reverse()
                ->take(10)
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{
     *     available:bool,
     *     window:array{since:string,until:string},
     *     repair_event_count:int,
     *     envelope_count:int,
     *     initiated_count:int,
     *     completed_count:int,
     *     executed_count:int,
     *     status_counts:array<string,int>,
     *     strategy_counts:array<string,int>,
     *     reason_counts:array<string,int>,
     *     latest_status:string|null,
     *     latest_strategy:string|null,
     *     requires_human_review:bool,
     *     filters:array<string,string>,
     *     recent_events:array<int,array<string,mixed>>
     * }
     */
    /**
     * @param  array<string,string|null>  $filters
     */
    public function repairReportForWindow(CarbonInterface $since, ?CarbonInterface $until = null, array $filters = []): array
    {
        $until ??= now();
        $filters = $this->normalizedRepairFilters($filters);

        if (! Schema::hasTable('atlas_ledger_events')) {
            return [
                'available' => false,
                'window' => [
                    'since' => $since->toJSON(),
                    'until' => $until->toJSON(),
                ],
                'filters' => $filters,
                'envelope_count' => 0,
                ...array_diff_key($this->repairEventSummary(collect()), ['events' => true]),
                'recent_events' => [],
            ];
        }

        $events = AtlasLedgerEvent::query()
            ->whereIn('event_type', [
                LedgerEventType::RepairInitiated->value,
                LedgerEventType::RepairCompleted->value,
            ])
            ->whereBetween('occurred_at', [$since, $until])
            ->orderBy('occurred_at')
            ->orderBy('event_id')
            ->get()
            ->map(fn (AtlasLedgerEvent $event): array => $this->repairEventFromEvent($event->toArray()))
            ->filter(fn (array $event): bool => $this->matchesRepairFilters($event, $filters))
            ->values();
        $summary = $this->repairEventSummary($events);

        return [
            'available' => true,
            'window' => [
                'since' => $since->toJSON(),
                'until' => $until->toJSON(),
            ],
            'filters' => $filters,
            'envelope_count' => $events->pluck('envelope_id')->filter()->unique()->count(),
            ...array_diff_key($summary, ['events' => true]),
            'recent_events' => $events
                ->reverse()
                ->take(10)
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function selfImprovementScheduleReportForWindow(CarbonInterface $since, ?CarbonInterface $until = null): array
    {
        $until ??= now();

        if (! Schema::hasTable('atlas_ledger_events')) {
            return [
                'available' => false,
                'window' => [
                    'since' => $since->toJSON(),
                    'until' => $until->toJSON(),
                ],
                ...array_diff_key($this->selfImprovementScheduleEventSummary(collect()), ['events' => true]),
                'recent_events' => [],
            ];
        }

        $events = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::SelfImprovementScheduleObserved->value)
            ->whereBetween('occurred_at', [$since, $until])
            ->orderBy('occurred_at')
            ->orderBy('event_id')
            ->get()
            ->map(fn (AtlasLedgerEvent $event): array => $this->selfImprovementScheduleEventFromEvent($event->toArray()))
            ->values();
        $completionByEnvelope = $this->selfImprovementCompletionByEnvelope($since, $until);
        $events = $events
            ->map(fn (array $event): array => $this->withSelfImprovementCompletion($event, $completionByEnvelope[$event['envelope_id'] ?? ''] ?? null))
            ->values();
        $inboxItemsById = $this->selfImprovementInboxItemsById($events
            ->pluck('emitted_inbox_item_ids')
            ->flatten()
            ->filter()
            ->unique()
            ->values()
            ->all());
        $inboxHydrationAvailable = Schema::hasTable('ai_inbox_items');
        $events = $events
            ->map(fn (array $event): array => $this->withSelfImprovementInboxItems($event, $inboxItemsById, $inboxHydrationAvailable))
            ->values();
        $summary = $this->selfImprovementScheduleEventSummary($events);

        return [
            'available' => true,
            'window' => [
                'since' => $since->toJSON(),
                'until' => $until->toJSON(),
            ],
            ...array_diff_key($summary, ['events' => true]),
            'recent_events' => $events
                ->reverse()
                ->take(10)
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<string,mixed>
     */
    public function inboxActionReportForWindow(CarbonInterface $since, ?CarbonInterface $until = null, array $filters = []): array
    {
        $until ??= now();
        $filters = $this->normalizedInboxActionFilters($filters);

        if (! Schema::hasTable('atlas_ledger_events')) {
            return [
                'available' => false,
                'window' => [
                    'since' => $since->toJSON(),
                    'until' => $until->toJSON(),
                ],
                'filters' => $filters,
                'inbox_action_count' => 0,
                ...array_diff_key($this->inboxActionSummary(collect()), ['events' => true]),
                'recent_events' => [],
            ];
        }

        $events = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::InboxActionRecorded->value)
            ->whereBetween('occurred_at', [$since, $until])
            ->orderBy('occurred_at')
            ->orderBy('event_id')
            ->get()
            ->map(fn (AtlasLedgerEvent $event): array => $this->inboxActionEventFromEvent($event->toArray()))
            ->filter(fn (array $event): bool => $this->matchesInboxActionFilters($event, $filters))
            ->values();
        $summary = $this->inboxActionSummary($events);

        return [
            'available' => true,
            'window' => [
                'since' => $since->toJSON(),
                'until' => $until->toJSON(),
            ],
            'filters' => $filters,
            'envelope_count' => $events->pluck('envelope_id')->filter()->unique()->count(),
            ...array_diff_key($summary, ['events' => true]),
            'recent_events' => $events
                ->reverse()
                ->take(10)
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{
     *     available:bool,
     *     window:array{since:string,until:string},
     *     observation_count:int,
     *     envelope_count:int,
     *     status_counts:array<string,int>,
     *     success_count:int,
     *     failure_count:int,
     *     worst_status:string|null,
     *     worst_severity:string|null,
     *     dimensions:array<string,array<string,int>>,
     *     stages:array<string,array<string,mixed>>,
     *     recent_breaches:array<int,array<string,mixed>>
     * }
     */
    /**
     * @param  array<string,string|null>  $filters
     */
    public function sloReportForWindow(CarbonInterface $since, ?CarbonInterface $until = null, array $filters = []): array
    {
        $until ??= now();
        $filters = $this->normalizedDimensionFilters($filters);

        if (! Schema::hasTable('atlas_ledger_events')) {
            return [
                'available' => false,
                'window' => [
                    'since' => $since->toJSON(),
                    'until' => $until->toJSON(),
                ],
                'filters' => $filters,
                'envelope_count' => 0,
                ...$this->sloObservationSummary(new Collection),
                'recent_breaches' => [],
            ];
        }

        $events = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::SloObserved->value)
            ->whereBetween('occurred_at', [$since, $until])
            ->orderBy('occurred_at')
            ->orderBy('event_id')
            ->get();
        $observations = $events
            ->map(fn (AtlasLedgerEvent $event): array => $this->sloObservationFromEvent($event->toArray()))
            ->filter(fn (array $observation): bool => $this->matchesDimensionFilters($observation, $filters))
            ->values();
        $summary = $this->sloObservationSummary($observations);

        return [
            'available' => true,
            'window' => [
                'since' => $since->toJSON(),
                'until' => $until->toJSON(),
            ],
            'filters' => $filters,
            'envelope_count' => $observations->pluck('envelope_id')->filter()->unique()->count(),
            ...$summary,
            'recent_breaches' => $observations
                ->filter(fn (array $observation): bool => in_array($observation['status'], ['warning', 'breach'], true) || $observation['success'] === false)
                ->reverse()
                ->take(10)
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $observations
     * @return array{
     *     observation_count:int,
     *     status_counts:array<string,int>,
     *     success_count:int,
     *     failure_count:int,
     *     worst_status:string|null,
     *     worst_severity:string|null,
     *     dimensions:array<string,array<string,int>>,
     *     stages:array<string,array<string,mixed>>
     * }
     */
    private function sloObservationSummary(Collection $observations): array
    {
        $statusCounts = $observations->countBy('status')->all();
        $successCount = $observations->where('success', true)->count();
        $failureCount = $observations->where('success', false)->count();
        $worstStatus = $this->worstSloValue($observations->pluck('status')->all(), [
            'breach' => 4,
            'warning' => 3,
            'ok' => 2,
            'unknown' => 1,
        ]);
        $worstSeverity = $this->worstSloValue($observations->pluck('severity')->all(), [
            'critical' => 5,
            'high' => 4,
            'medium' => 3,
            'low' => 2,
            'unknown' => 1,
        ]);
        $stages = $observations
            ->groupBy('stage')
            ->map(fn (Collection $stageObservations): array => $this->sloStageSummary($stageObservations))
            ->all();
        $reviewSignal = $this->sloReviewSignal($observations, $worstStatus, $worstSeverity, $failureCount, $stages);

        return [
            'observation_count' => $observations->count(),
            'status_counts' => $statusCounts,
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'worst_status' => $worstStatus,
            'worst_severity' => $worstSeverity,
            'dimensions' => $this->dimensionSummary($observations),
            'stages' => $stages,
            'review_signal' => $reviewSignal,
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $observations
     * @param  array<string,array<string,mixed>>  $stages
     * @return array{status:string,severity:string,review_required:bool,reasons:array<int,string>,recommended_action:string}
     */
    private function sloReviewSignal(Collection $observations, ?string $worstStatus, ?string $worstSeverity, int $failureCount, array $stages): array
    {
        $reasons = collect($stages)
            ->pluck('violations')
            ->flatten()
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($observations->isEmpty()) {
            return [
                'status' => 'unknown',
                'severity' => 'low',
                'review_required' => false,
                'reasons' => ['no_slo_observations_in_window'],
                'recommended_action' => 'wait_for_slo_evidence',
            ];
        }

        if ($worstStatus === 'breach' || $failureCount > 0) {
            return [
                'status' => 'breach',
                'severity' => in_array($worstSeverity, ['critical', 'high'], true) ? 'high' : 'medium',
                'review_required' => true,
                'reasons' => array_values(array_unique([...$reasons, 'slo_breach_detected'])),
                'recommended_action' => 'open_reviewable_slo_regression_proposal',
            ];
        }

        if ($worstStatus === 'warning') {
            return [
                'status' => 'warning',
                'severity' => in_array($worstSeverity, ['critical', 'high'], true) ? 'medium' : 'low',
                'review_required' => true,
                'reasons' => array_values(array_unique([...$reasons, 'slo_warning_detected'])),
                'recommended_action' => 'open_reviewable_slo_drift_proposal',
            ];
        }

        return [
            'status' => 'ok',
            'severity' => 'none',
            'review_required' => false,
            'reasons' => [],
            'recommended_action' => 'none',
        ];
    }

    /**
     * @param  array<string,mixed>  $event
     * @return array<string,mixed>
     */
    private function sloObservationFromEvent(array $event): array
    {
        return [
            'event_id' => $event['event_id'] ?? null,
            'envelope_id' => $event['envelope_id'] ?? null,
            'tenant_id' => $event['tenant_id'] ?? null,
            'operator_id' => $event['operator_id'] ?? null,
            'trace_id' => $event['trace_id'] ?? null,
            'stage' => (string) data_get($event, 'payload.stage', data_get($event, 'payload.slo.stage', 'unknown')),
            'duration_ms' => (int) data_get($event, 'payload.slo.duration_ms', 0),
            'success' => (bool) data_get($event, 'payload.slo.success', false),
            'status' => (string) data_get($event, 'payload.status', data_get($event, 'payload.slo.status', 'unknown')),
            'severity' => (string) data_get($event, 'payload.severity', data_get($event, 'payload.slo.severity', 'unknown')),
            'violations' => (array) data_get($event, 'payload.violations', data_get($event, 'payload.slo.violations', [])),
            'dimensions' => $this->normalizedDimensions((array) data_get($event, 'payload.dimensions', [])),
            'occurred_at' => $event['occurred_at'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $event
     * @return array<string,mixed>
     */
    private function repairEventFromEvent(array $event): array
    {
        $decision = (array) data_get($event, 'payload.decision', []);
        $eventType = (string) ($event['event_type'] ?? 'unknown');
        $status = (string) data_get($decision, 'status', data_get($event, 'payload.status', 'unknown'));
        $strategy = data_get($decision, 'strategy', data_get($event, 'payload.strategy'));

        return [
            'event_id' => $event['event_id'] ?? null,
            'event_type' => $eventType,
            'envelope_id' => $event['envelope_id'] ?? null,
            'receipt_id' => $event['receipt_id'] ?? null,
            'correlation_id' => $event['correlation_id'] ?? null,
            'causation_id' => $event['causation_id'] ?? null,
            'emitter_stage' => $event['emitter_stage'] ?? null,
            'payload_hash' => $event['payload_hash'] ?? null,
            'occurred_at' => $event['occurred_at'] ?? null,
            'status' => $status,
            'strategy' => is_scalar($strategy) ? (string) $strategy : null,
            'next_attempt' => (int) data_get($decision, 'next_attempt', 0),
            'reasons' => array_values(array_filter(array_map(
                fn (mixed $reason): ?string => is_scalar($reason) ? trim((string) $reason) : null,
                (array) data_get($decision, 'reasons', data_get($event, 'payload.reasons', [])),
            ))),
            'failure_domain' => (string) data_get($event, 'payload.failure_classification.failure_domain', data_get($event, 'payload.request.failure_classification.failure_domain', 'unknown')),
            'repair_executed' => (bool) data_get($event, 'payload.repair_executed', false),
            'attempt' => data_get($event, 'payload.attempt'),
            'decision_hash' => data_get($event, 'payload.decision_hash', data_get($event, 'payload.decision.evidence_payload.decision_hash')),
            'result_hash' => data_get($event, 'payload.result_hash'),
        ];
    }

    /**
     * @param  array<string,mixed>  $event
     * @return array<string,mixed>
     */
    private function decisionReceiptEventFromEvent(array $event): array
    {
        $payload = (array) ($event['payload'] ?? []);
        $receiptHash = $this->nullableScalar(data_get($payload, 'receipt_hash'));
        $chainHash = $this->nullableScalar(data_get($payload, 'chain_hash'));
        $parentChainHash = $this->nullableScalar(data_get($payload, 'parent_chain_hash'));
        $expectedReceiptHash = $receiptHash !== null ? DecisionReceiptHash::hash([
            'receipt_id' => $this->scalarOrDefault(data_get($payload, 'receipt_id'), ''),
            'envelope_id' => $this->scalarOrDefault(data_get($payload, 'envelope_id'), ''),
            'schema_version' => $this->scalarOrDefault(data_get($payload, 'schema_version'), ''),
            'issued_at' => $this->scalarOrDefault(data_get($payload, 'issued_at'), ''),
            'expires_at' => $this->scalarOrDefault(data_get($payload, 'expires_at'), ''),
            'dry_run' => (bool) data_get($payload, 'dry_run', false),
            'signed_by' => $this->scalarOrDefault(data_get($payload, 'signed_by'), ''),
            'inputs_hash' => $this->scalarOrDefault(data_get($payload, 'inputs_hash'), ''),
            'parent_receipt_id' => $this->nullableScalar(data_get($payload, 'parent_receipt_id')),
        ]) : null;
        $expectedChainHash = ($chainHash !== null && $receiptHash !== null) ? DecisionReceiptHash::hash([
            'parent_chain_hash' => $parentChainHash,
            'receipt_hash' => $receiptHash,
        ]) : null;
        $receiptIntegrityStatus = $receiptHash === null
            ? 'unverifiable'
            : (hash_equals($expectedReceiptHash ?? '', $receiptHash) ? 'ok' : 'mismatch');
        $chainIntegrityStatus = $chainHash === null || $receiptHash === null
            ? 'unverifiable'
            : (hash_equals($expectedChainHash ?? '', $chainHash) ? 'ok' : 'mismatch');

        return [
            'event_id' => $event['event_id'] ?? null,
            'event_type' => $event['event_type'] ?? null,
            'envelope_id' => $event['envelope_id'] ?? null,
            'receipt_id' => $event['receipt_id'] ?? data_get($payload, 'receipt_id'),
            'correlation_id' => $event['correlation_id'] ?? null,
            'emitter_stage' => $event['emitter_stage'] ?? null,
            'payload_hash' => $event['payload_hash'] ?? null,
            'occurred_at' => $event['occurred_at'] ?? null,
            'schema_version' => data_get($payload, 'schema_version'),
            'domain' => data_get($payload, 'domain'),
            'flow' => data_get($payload, 'flow'),
            'risk' => data_get($payload, 'risk'),
            'provider' => data_get($payload, 'provider_selection.primary'),
            'model' => data_get($payload, 'provider_selection.model'),
            'dry_run' => (bool) data_get($payload, 'dry_run', false),
            'inputs_hash' => data_get($payload, 'inputs_hash'),
            'receipt_hash' => $receiptHash,
            'expected_receipt_hash' => $expectedReceiptHash,
            'receipt_integrity_status' => $receiptIntegrityStatus,
            'parent_receipt_id' => data_get($payload, 'parent_receipt_id'),
            'parent_chain_hash' => $parentChainHash,
            'chain_hash' => $chainHash,
            'expected_chain_hash' => $expectedChainHash,
            'chain_integrity_status' => $chainIntegrityStatus,
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $events
     * @return array<string,mixed>
     */
    private function decisionReceiptEventSummary(Collection $events): array
    {
        $invalidEvents = $events->filter(fn (array $event): bool => in_array('mismatch', [
            $event['receipt_integrity_status'] ?? null,
            $event['chain_integrity_status'] ?? null,
        ], true));
        $latest = $events->last();

        return [
            'decision_event_count' => $events->count(),
            'valid_receipt_hash_count' => $events->where('receipt_integrity_status', 'ok')->count(),
            'valid_chain_hash_count' => $events->where('chain_integrity_status', 'ok')->count(),
            'invalid_count' => $invalidEvents->count(),
            'latest_receipt_id' => is_array($latest) ? ($latest['receipt_id'] ?? null) : null,
            'latest_chain_hash' => is_array($latest) ? ($latest['chain_hash'] ?? null) : null,
            'review_signal' => $this->decisionReceiptReviewSignal($events, $invalidEvents),
            'events' => $events->all(),
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $events
     * @param  Collection<int,array<string,mixed>>  $invalidEvents
     * @return array{status:string,severity:string,review_required:bool,reasons:array<int,string>,recommended_action:string}
     */
    private function decisionReceiptReviewSignal(Collection $events, Collection $invalidEvents): array
    {
        if ($events->isEmpty()) {
            return [
                'status' => 'unknown',
                'severity' => 'low',
                'review_required' => false,
                'reasons' => ['no_decision_receipt_events_for_envelope'],
                'recommended_action' => 'wait_for_decision_receipt_evidence',
            ];
        }

        if ($invalidEvents->isNotEmpty()) {
            return [
                'status' => 'breach',
                'severity' => 'high',
                'review_required' => true,
                'reasons' => array_values(array_unique($invalidEvents
                    ->flatMap(fn (array $event): array => [
                        ($event['receipt_integrity_status'] ?? null) === 'mismatch' ? 'decision_receipt_hash_mismatch' : null,
                        ($event['chain_integrity_status'] ?? null) === 'mismatch' ? 'decision_receipt_chain_hash_mismatch' : null,
                    ])
                    ->filter()
                    ->all())),
                'recommended_action' => 'open_reviewable_decision_receipt_replay_proposal',
            ];
        }

        return [
            'status' => 'ok',
            'severity' => 'none',
            'review_required' => false,
            'reasons' => [],
            'recommended_action' => 'none',
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $events
     * @return array{
     *     repair_event_count:int,
     *     initiated_count:int,
     *     completed_count:int,
     *     executed_count:int,
     *     status_counts:array<string,int>,
     *     strategy_counts:array<string,int>,
     *     reason_counts:array<string,int>,
     *     latest_status:string|null,
     *     latest_strategy:string|null,
     *     requires_human_review:bool,
     *     events:array<int,array<string,mixed>>
     * }
     */
    private function repairEventSummary(Collection $events): array
    {
        $latest = $events->last();
        $statusCounts = $events->pluck('status')->filter()->countBy()->all();
        $strategyCounts = $events->pluck('strategy')->filter()->countBy()->all();
        $reasonCounts = $events->pluck('reasons')->flatten()->filter()->countBy()->all();
        $requiresHumanReview = $events->contains(fn (array $event): bool => ($event['status'] ?? null) === 'needs_human_review' || ($event['strategy'] ?? null) === 'human_review');
        $reviewSignal = $this->repairReviewSignal($events, $statusCounts, $strategyCounts, $reasonCounts, $requiresHumanReview);

        return [
            'repair_event_count' => $events->count(),
            'initiated_count' => $events->where('event_type', LedgerEventType::RepairInitiated->value)->count(),
            'completed_count' => $events->where('event_type', LedgerEventType::RepairCompleted->value)->count(),
            'executed_count' => $events->where('repair_executed', true)->count(),
            'status_counts' => $statusCounts,
            'strategy_counts' => $strategyCounts,
            'reason_counts' => $reasonCounts,
            'latest_status' => is_array($latest) ? ($latest['status'] ?? null) : null,
            'latest_strategy' => is_array($latest) ? ($latest['strategy'] ?? null) : null,
            'requires_human_review' => $requiresHumanReview,
            'review_signal' => $reviewSignal,
            'events' => $events->all(),
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $events
     * @param  array<string,int>  $statusCounts
     * @param  array<string,int>  $strategyCounts
     * @param  array<string,int>  $reasonCounts
     * @return array{status:string,severity:string,review_required:bool,reasons:array<int,string>,recommended_action:string}
     */
    private function repairReviewSignal(Collection $events, array $statusCounts, array $strategyCounts, array $reasonCounts, bool $requiresHumanReview): array
    {
        if ($events->isEmpty()) {
            return [
                'status' => 'unknown',
                'severity' => 'low',
                'review_required' => false,
                'reasons' => ['no_repair_events_in_window'],
                'recommended_action' => 'wait_for_repair_loop_evidence',
            ];
        }

        $blockedCount = (int) ($statusCounts['repair_blocked'] ?? 0) + (int) ($statusCounts['repair_exhausted'] ?? 0);
        $reasons = array_values(array_unique([
            ...array_keys($reasonCounts),
            ...($requiresHumanReview ? ['repair_requires_human_review'] : []),
            ...($blockedCount > 0 ? ['repair_blocked_or_exhausted'] : []),
        ]));

        if ($blockedCount > 0) {
            return [
                'status' => 'breach',
                'severity' => 'high',
                'review_required' => true,
                'reasons' => $reasons,
                'recommended_action' => 'open_reviewable_repair_loop_policy_proposal',
            ];
        }

        if ($requiresHumanReview) {
            return [
                'status' => 'warning',
                'severity' => 'medium',
                'review_required' => true,
                'reasons' => $reasons,
                'recommended_action' => 'open_reviewable_repair_loop_human_review_proposal',
            ];
        }

        $dominantStrategy = collect($strategyCounts)->filter(fn (int $count): bool => $count >= 2)->keys()->first();
        if (is_string($dominantStrategy) && $dominantStrategy !== '') {
            return [
                'status' => 'warning',
                'severity' => 'low',
                'review_required' => true,
                'reasons' => ['recurring_repair_strategy:'.$dominantStrategy],
                'recommended_action' => 'open_reviewable_repair_loop_pattern_proposal',
            ];
        }

        return [
            'status' => 'ok',
            'severity' => 'none',
            'review_required' => false,
            'reasons' => [],
            'recommended_action' => 'none',
        ];
    }

    private function nullableScalar(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function scalarOrDefault(mixed $value, string $default): string
    {
        return $this->nullableScalar($value) ?? $default;
    }

    /**
     * @param  array<string,mixed>  $event
     * @return array<string,mixed>
     */
    private function selfImprovementScheduleEventFromEvent(array $event): array
    {
        $scheduleHealth = (array) data_get($event, 'payload.schedule_health', []);

        return [
            'event_id' => $event['event_id'] ?? null,
            'event_type' => $event['event_type'] ?? null,
            'envelope_id' => $event['envelope_id'] ?? null,
            'correlation_id' => $event['correlation_id'] ?? null,
            'emitter_stage' => $event['emitter_stage'] ?? null,
            'payload_hash' => $event['payload_hash'] ?? null,
            'occurred_at' => $event['occurred_at'] ?? null,
            'flow' => data_get($event, 'payload.flow'),
            'health_status' => (string) ($scheduleHealth['health_status'] ?? 'unknown'),
            'issues' => array_values((array) ($scheduleHealth['issues'] ?? [])),
            'enabled' => (bool) ($scheduleHealth['enabled'] ?? false),
            'schedulable' => (bool) ($scheduleHealth['schedulable'] ?? false),
            'scheduler_status' => (string) data_get($scheduleHealth, 'scheduler_registration.status', 'unknown'),
            'registered_command_count' => (int) data_get($scheduleHealth, 'scheduler_registration.registered_command_count', 0),
            'skipped_reason' => data_get($scheduleHealth, 'scheduler_registration.skipped_reason'),
            'flow_count' => (int) ($scheduleHealth['flow_count'] ?? 0),
            'cadence_counts' => (array) ($scheduleHealth['cadence_counts'] ?? []),
            'invalid_flow_count' => (int) ($scheduleHealth['invalid_flow_count'] ?? 0),
            'defaulted' => (bool) ($scheduleHealth['defaulted'] ?? false),
            'emit' => (bool) ($scheduleHealth['emit'] ?? false),
            'plan_hash' => $scheduleHealth['plan_hash'] ?? null,
            'plan_hash_algorithm' => $scheduleHealth['plan_hash_algorithm'] ?? null,
            'time' => $scheduleHealth['time'] ?? null,
            'timezone' => $scheduleHealth['timezone'] ?? null,
            'next_run_at' => $scheduleHealth['next_run_at'] ?? null,
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function selfImprovementCompletionByEnvelope(CarbonInterface $since, CarbonInterface $until): array
    {
        return AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::OperationCompleted->value)
            ->where('emitter_stage', 'atlas.self_improvement')
            ->whereBetween('occurred_at', [$since, $until])
            ->orderBy('occurred_at')
            ->orderBy('event_id')
            ->get()
            ->mapWithKeys(function (AtlasLedgerEvent $event): array {
                $payload = (array) ($event->payload ?? []);

                return [(string) $event->envelope_id => [[
                    'event_id' => $event->event_id,
                    'finding_count' => (int) ($payload['finding_count'] ?? 0),
                    'emitted_count' => (int) ($payload['emitted_count'] ?? 0),
                    'emitted_inbox_item_ids' => array_values((array) ($payload['emitted_inbox_item_ids'] ?? [])),
                    'completed_at' => $event->occurred_at?->toJSON(),
                ]]];
            })
            ->map(fn (array $items): array => end($items) ?: [])
            ->all();
    }

    /**
     * @param  array<string,mixed>|null  $completion
     * @return array<string,mixed>
     */
    private function withSelfImprovementCompletion(array $event, ?array $completion): array
    {
        $completion ??= [];

        return [
            ...$event,
            'completed' => $completion !== [],
            'finding_count' => (int) ($completion['finding_count'] ?? 0),
            'emitted_count' => (int) ($completion['emitted_count'] ?? 0),
            'emitted_inbox_item_ids' => array_values((array) ($completion['emitted_inbox_item_ids'] ?? [])),
            'completed_at' => $completion['completed_at'] ?? null,
        ];
    }

    /**
     * @param  array<int,mixed>  $ids
     * @return array<string,array<string,mixed>>
     */
    private function selfImprovementInboxItemsById(array $ids): array
    {
        $ids = collect($ids)
            ->map(fn (mixed $id): string => trim((string) $id))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($ids === [] || ! Schema::hasTable('ai_inbox_items')) {
            return [];
        }

        return AiInboxItem::query()
            ->whereIn('id', $ids)
            ->get()
            ->mapWithKeys(fn (AiInboxItem $item): array => [
                $item->id => $this->selfImprovementInboxItemSummary($item),
            ])
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function selfImprovementInboxItemSummary(AiInboxItem $item): array
    {
        return [
            'id' => $item->id,
            'status' => $item->status,
            'type' => $item->type,
            'category' => $item->category,
            'severity' => $item->severity,
            'title' => $item->title,
            'source_type' => $item->source_type,
            'source_id' => $item->source_id,
            'deep_link' => $item->deep_link,
            'review_signal' => data_get($item->payload, 'proposal_contract.review_signal')
                ?? data_get($item->payload, 'review_signal'),
            'created_at' => $item->created_at?->toJSON(),
            'updated_at' => $item->updated_at?->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $event
     * @param  array<string,array<string,mixed>>  $inboxItemsById
     * @return array<string,mixed>
     */
    private function withSelfImprovementInboxItems(array $event, array $inboxItemsById, bool $hydrationAvailable): array
    {
        $ids = array_values((array) ($event['emitted_inbox_item_ids'] ?? []));
        $missingIds = collect($ids)
            ->map(fn (mixed $id): string => trim((string) $id))
            ->filter()
            ->reject(fn (string $id): bool => array_key_exists($id, $inboxItemsById))
            ->values()
            ->all();

        return [
            ...$event,
            'emitted_inbox_item_hydration_available' => $hydrationAvailable,
            'emitted_inbox_item_missing_ids' => $missingIds,
            'emitted_inbox_items' => collect($ids)
                ->map(fn (mixed $id): ?array => $inboxItemsById[(string) $id] ?? null)
                ->filter()
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $events
     * @return array<string,mixed>
     */
    private function selfImprovementScheduleEventSummary(Collection $events): array
    {
        $latest = $events->last();
        $issueCounts = $events->pluck('issues')->flatten()->filter()->countBy()->all();
        $emittedInboxItemIds = $events
            ->pluck('emitted_inbox_item_ids')
            ->flatten()
            ->filter()
            ->unique()
            ->values()
            ->all();
        $emittedInboxItems = $events
            ->pluck('emitted_inbox_items')
            ->flatten(1)
            ->filter()
            ->unique('id')
            ->values()
            ->all();
        $missingInboxItemIds = $events
            ->pluck('emitted_inbox_item_missing_ids')
            ->flatten()
            ->filter()
            ->unique()
            ->values()
            ->all();
        $warningCount = $events->filter(fn (array $event): bool => in_array($event['health_status'] ?? null, ['warning', 'disabled'], true)
            || ($event['scheduler_status'] ?? null) === 'skipped'
            || (int) ($event['invalid_flow_count'] ?? 0) > 0)->count();
        $reviewSignal = $this->selfImprovementScheduleReviewSignal($events, $warningCount, $issueCounts);

        return [
            'schedule_observation_count' => $events->count(),
            'envelope_count' => $events->pluck('envelope_id')->filter()->unique()->count(),
            'health_status_counts' => $events->pluck('health_status')->filter()->countBy()->all(),
            'scheduler_status_counts' => $events->pluck('scheduler_status')->filter()->countBy()->all(),
            'issue_counts' => $issueCounts,
            'warning_count' => $warningCount,
            'completed_count' => $events->where('completed', true)->count(),
            'emitted_count' => $events->sum(fn (array $event): int => (int) ($event['emitted_count'] ?? 0)),
            'emitted_inbox_item_ids' => $emittedInboxItemIds,
            'emitted_inbox_items' => $emittedInboxItems,
            'emitted_inbox_item_hydration_available' => Schema::hasTable('ai_inbox_items'),
            'emitted_inbox_item_missing_ids' => $missingInboxItemIds,
            'latest_health_status' => is_array($latest) ? ($latest['health_status'] ?? null) : null,
            'latest_scheduler_status' => is_array($latest) ? ($latest['scheduler_status'] ?? null) : null,
            'latest_plan_hash' => is_array($latest) ? ($latest['plan_hash'] ?? null) : null,
            'latest_next_run_at' => is_array($latest) ? ($latest['next_run_at'] ?? null) : null,
            'review_required' => $warningCount > 0,
            'health' => [
                'status' => $warningCount > 0 ? 'warning' : ($events->isEmpty() ? 'unknown' : 'ok'),
                'reasons' => $warningCount > 0 ? array_keys($issueCounts + ['self_improvement_schedule_warning_observed' => 1]) : ($events->isEmpty() ? ['no_self_improvement_schedule_observations_in_window'] : []),
            ],
            'review_signal' => $reviewSignal,
            'events' => $events->all(),
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $events
     * @param  array<string,int>  $issueCounts
     * @return array{status:string,severity:string,review_required:bool,reasons:array<int,string>,recommended_action:string}
     */
    private function selfImprovementScheduleReviewSignal(Collection $events, int $warningCount, array $issueCounts): array
    {
        if ($events->isEmpty()) {
            return [
                'status' => 'unknown',
                'severity' => 'low',
                'review_required' => false,
                'reasons' => ['no_self_improvement_schedule_observations_in_window'],
                'recommended_action' => 'wait_for_next_self_improvement_cycle',
            ];
        }

        if ($warningCount === 0) {
            return [
                'status' => 'ok',
                'severity' => 'none',
                'review_required' => false,
                'reasons' => [],
                'recommended_action' => 'none',
            ];
        }

        $reasons = array_keys($issueCounts + ['self_improvement_schedule_warning_observed' => 1]);
        $hasSkippedScheduler = $events->contains(fn (array $event): bool => ($event['scheduler_status'] ?? null) === 'skipped');
        $hasInvalidFlows = $events->contains(fn (array $event): bool => (int) ($event['invalid_flow_count'] ?? 0) > 0);
        $severity = $hasSkippedScheduler ? 'high' : ($hasInvalidFlows || $warningCount > 1 ? 'medium' : 'low');

        return [
            'status' => 'warning',
            'severity' => $severity,
            'review_required' => true,
            'reasons' => $reasons,
            'recommended_action' => 'open_reviewable_self_improvement_schedule_proposal',
        ];
    }

    /**
     * @param  array<string,mixed>  $event
     * @return array<string,mixed>
     */
    private function inboxActionEventFromEvent(array $event): array
    {
        $payload = (array) data_get($event, 'payload', []);
        $inboxItem = (array) data_get($payload, 'inbox_item', []);
        $actor = (array) data_get($payload, 'actor', []);
        $result = (array) data_get($payload, 'result', []);

        return [
            'event_id' => $event['event_id'] ?? null,
            'event_type' => $event['event_type'] ?? null,
            'envelope_id' => $event['envelope_id'] ?? null,
            'correlation_id' => $event['correlation_id'] ?? null,
            'emitter_stage' => $event['emitter_stage'] ?? null,
            'payload_hash' => $event['payload_hash'] ?? null,
            'occurred_at' => $event['occurred_at'] ?? null,
            'schema_version' => data_get($payload, 'schema_version'),
            'action' => data_get($payload, 'action'),
            'idempotency_key' => data_get($payload, 'idempotency_key'),
            'actor_type' => data_get($actor, 'type'),
            'actor_id' => data_get($actor, 'id'),
            'inbox_item_id' => data_get($inboxItem, 'id'),
            'inbox_item_type' => data_get($inboxItem, 'type'),
            'inbox_item_category' => data_get($inboxItem, 'category'),
            'inbox_item_severity' => data_get($inboxItem, 'severity'),
            'inbox_item_status' => data_get($inboxItem, 'status'),
            'source_type' => data_get($inboxItem, 'source_type'),
            'source_id' => data_get($inboxItem, 'source_id'),
            'dedupe_key' => data_get($inboxItem, 'dedupe_key'),
            'recommended_action' => data_get($payload, 'recommended_action'),
            'review_signal_status' => data_get($payload, 'review_signal.status'),
            'review_signal_severity' => data_get($payload, 'review_signal.severity'),
            'result_action' => data_get($result, 'payload.action'),
            'diff_ref_count' => count((array) data_get($result, 'payload.diff_refs', [])),
            'file_ref_count' => count((array) data_get($result, 'payload.file_refs', [])),
            'rivals_review_schema_version' => data_get($result, 'rivals_review_action.schema_version'),
            'rivals_review_id' => data_get($result, 'rivals_review_action.recorded_review_id'),
            'rivals_case_id' => data_get($result, 'rivals_review_action.case_id'),
            'rivals_horizon_days' => data_get($result, 'rivals_review_action.horizon_days'),
            'rivals_regret_score' => data_get($result, 'rivals_review_action.scores.regret'),
            'rivals_alignment_score' => data_get($result, 'rivals_review_action.scores.alignment'),
            'rivals_agency_score' => data_get($result, 'rivals_review_action.scores.agency'),
            'rivals_remaining_due_review_count' => data_get($result, 'rivals_review_action.remaining_due_review_count'),
            'provider_cost_rate_schema_version' => data_get($result, 'provider_cost_rate_action.schema_version'),
            'provider_cost_rate_provider' => data_get($result, 'provider_cost_rate_action.provider'),
            'provider_cost_rate_model' => data_get($result, 'provider_cost_rate_action.model'),
            'provider_cost_rate_input_microusd' => data_get($result, 'provider_cost_rate_action.input_microusd_per_1k'),
            'provider_cost_rate_output_microusd' => data_get($result, 'provider_cost_rate_action.output_microusd_per_1k'),
            'provider_cost_rate_currency' => data_get($result, 'provider_cost_rate_action.currency'),
            'provider_cost_rate_effective_from' => data_get($result, 'provider_cost_rate_action.effective_from'),
            'provider_cost_rate_effective_until' => data_get($result, 'provider_cost_rate_action.effective_until'),
            'provider_cost_rate_applied' => data_get($result, 'provider_cost_rate_action.applied'),
            'provider_cost_rate_id' => data_get($result, 'upserted_rate.id'),
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $events
     * @return array<string,mixed>
     */
    private function inboxActionSummary(Collection $events): array
    {
        $reviewedPatchCount = $events
            ->filter(fn (array $event): bool => ($event['action'] ?? null) === 'review_patch')
            ->count();
        $withDiffRefsCount = $events
            ->filter(fn (array $event): bool => (int) ($event['diff_ref_count'] ?? 0) > 0)
            ->count();
        $rivalsReviewRecordedCount = $events
            ->filter(fn (array $event): bool => ($event['action'] ?? null) === 'record_rivals_review')
            ->count();
        $rivalsReviewWithScoresCount = $events
            ->filter(fn (array $event): bool => ($event['action'] ?? null) === 'record_rivals_review')
            ->filter(fn (array $event): bool => is_numeric($event['rivals_regret_score'] ?? null)
                && is_numeric($event['rivals_alignment_score'] ?? null)
                && is_numeric($event['rivals_agency_score'] ?? null))
            ->count();
        $providerCostRateActionCount = $events
            ->filter(fn (array $event): bool => ($event['action'] ?? null) === 'configure_provider_cost_rates')
            ->count();
        $providerCostRateAppliedCount = $events
            ->filter(fn (array $event): bool => ($event['action'] ?? null) === 'configure_provider_cost_rates')
            ->filter(fn (array $event): bool => (bool) ($event['provider_cost_rate_applied'] ?? false))
            ->count();
        $reviewSignal = $this->inboxActionReviewSignal(
            $events,
            $reviewedPatchCount,
            $withDiffRefsCount,
            $rivalsReviewRecordedCount,
            $rivalsReviewWithScoresCount,
            $providerCostRateActionCount,
            $providerCostRateAppliedCount,
        );

        return [
            'inbox_action_count' => $events->count(),
            'action_counts' => $events->pluck('action')->filter()->countBy()->all(),
            'actor_type_counts' => $events->pluck('actor_type')->filter()->countBy()->all(),
            'category_counts' => $events->pluck('inbox_item_category')->filter()->countBy()->all(),
            'severity_counts' => $events->pluck('inbox_item_severity')->filter()->countBy()->all(),
            'recommended_action_counts' => $events->pluck('recommended_action')->filter()->countBy()->all(),
            'reviewed_patch_count' => $reviewedPatchCount,
            'with_diff_refs_count' => $withDiffRefsCount,
            'rivals_review_recorded_count' => $rivalsReviewRecordedCount,
            'rivals_review_with_scores_count' => $rivalsReviewWithScoresCount,
            'provider_cost_rate_action_count' => $providerCostRateActionCount,
            'provider_cost_rate_applied_count' => $providerCostRateAppliedCount,
            'provider_cost_rate_provider_counts' => $events->pluck('provider_cost_rate_provider')->filter()->countBy()->all(),
            'provider_cost_rate_model_counts' => $events
                ->map(fn (array $event): ?string => ($event['provider_cost_rate_provider'] ?? null) && ($event['provider_cost_rate_model'] ?? null)
                    ? $event['provider_cost_rate_provider'].':'.$event['provider_cost_rate_model']
                    : null)
                ->filter()
                ->countBy()
                ->all(),
            'review_signal' => $reviewSignal,
            'events' => $events->all(),
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $events
     * @return array{status:string,severity:string,review_required:bool,reasons:array<int,string>,recommended_action:string}
     */
    private function inboxActionReviewSignal(
        Collection $events,
        int $reviewedPatchCount,
        int $withDiffRefsCount,
        int $rivalsReviewRecordedCount,
        int $rivalsReviewWithScoresCount,
        int $providerCostRateActionCount,
        int $providerCostRateAppliedCount,
    ): array {
        if ($events->isEmpty()) {
            return [
                'status' => 'unknown',
                'severity' => 'low',
                'review_required' => false,
                'reasons' => ['no_inbox_action_events_in_window'],
                'recommended_action' => 'wait_for_inbox_action_evidence',
            ];
        }

        if ($rivalsReviewRecordedCount > 0 && $rivalsReviewWithScoresCount === $rivalsReviewRecordedCount) {
            return [
                'status' => 'ok',
                'severity' => 'none',
                'review_required' => false,
                'reasons' => ['rivals_strategy_human_scores_recorded'],
                'recommended_action' => 'none',
            ];
        }

        if ($rivalsReviewRecordedCount > 0) {
            return [
                'status' => 'warning',
                'severity' => 'medium',
                'review_required' => true,
                'reasons' => ['record_rivals_review_action_without_scores'],
                'recommended_action' => 'open_reviewable_inbox_action_evidence_proposal',
            ];
        }

        if ($providerCostRateActionCount > 0 && $providerCostRateAppliedCount === $providerCostRateActionCount) {
            return [
                'status' => 'ok',
                'severity' => 'none',
                'review_required' => false,
                'reasons' => ['provider_cost_rates_configured'],
                'recommended_action' => 'none',
            ];
        }

        if ($providerCostRateActionCount > 0) {
            return [
                'status' => 'warning',
                'severity' => 'medium',
                'review_required' => true,
                'reasons' => ['configure_provider_cost_rates_action_without_applied_rate'],
                'recommended_action' => 'configure_provider_cost_rates',
            ];
        }

        if ($reviewedPatchCount > 0 && $withDiffRefsCount > 0) {
            return [
                'status' => 'ok',
                'severity' => 'none',
                'review_required' => false,
                'reasons' => ['human_review_action_with_patch_context_recorded'],
                'recommended_action' => 'none',
            ];
        }

        if ($reviewedPatchCount > 0) {
            return [
                'status' => 'warning',
                'severity' => 'medium',
                'review_required' => true,
                'reasons' => ['review_patch_action_without_diff_refs'],
                'recommended_action' => 'open_reviewable_inbox_action_evidence_proposal',
            ];
        }

        return [
            'status' => 'ok',
            'severity' => 'none',
            'review_required' => false,
            'reasons' => [],
            'recommended_action' => 'none',
        ];
    }

    /**
     * @param  array<string,mixed>  $event
     * @return array<string,mixed>
     */
    private function kernelPipelineEventFromEvent(array $event): array
    {
        $eventType = (string) ($event['event_type'] ?? 'unknown');
        $status = (string) data_get($event, 'payload.status', $eventType === LedgerEventType::KernelPipelineRejected->value ? 'rejected' : 'accepted');

        return [
            'event_id' => $event['event_id'] ?? null,
            'event_type' => $eventType,
            'envelope_id' => $event['envelope_id'] ?? null,
            'receipt_id' => $event['receipt_id'] ?? null,
            'correlation_id' => $event['correlation_id'] ?? null,
            'causation_id' => $event['causation_id'] ?? null,
            'emitter_stage' => $event['emitter_stage'] ?? null,
            'payload_hash' => $event['payload_hash'] ?? null,
            'occurred_at' => $event['occurred_at'] ?? null,
            'status' => $status,
            'pipeline_id' => data_get($event, 'payload.pipeline.pipeline_id'),
            'schema_version' => data_get($event, 'payload.pipeline.schema_version'),
            'mode' => data_get($event, 'payload.pipeline.mode'),
            'stage_count' => (int) data_get($event, 'payload.pipeline.stage_count', 0),
            'canonical_flow_hash' => data_get($event, 'payload.pipeline.canonical_flow_hash'),
            'provider_execution_allowed' => (bool) data_get($event, 'payload.pipeline.provider_execution_allowed', false),
            'runtime_execution_allowed' => (bool) data_get($event, 'payload.pipeline.runtime_execution_allowed', false),
            'surface_id' => data_get($event, 'payload.surface.surface_id'),
            'binding_surface' => data_get($event, 'payload.surface.binding_surface'),
            'command' => data_get($event, 'payload.surface.command'),
            'input_mode' => data_get($event, 'payload.surface.input_mode'),
            'surface_contract_required' => (bool) data_get($event, 'payload.surface_contract.required', false),
            'surface_contract_source' => data_get($event, 'payload.surface_contract.source'),
            'surface_must_not_decide' => (bool) data_get($event, 'payload.surface_contract.surface_must_not_decide', false),
            'provider_execution_blocked_until_runtime_migration' => (bool) data_get($event, 'payload.surface_contract.provider_execution_blocked_until_runtime_migration', false),
            'runtime_execution_blocked_until_runtime_migration' => (bool) data_get($event, 'payload.surface_contract.runtime_execution_blocked_until_runtime_migration', false),
            'domain' => data_get($event, 'payload.routing.domain'),
            'flow' => data_get($event, 'payload.routing.flow'),
            'runtime' => data_get($event, 'payload.routing.runtime'),
            'violations' => array_values(array_filter(array_map(
                fn (mixed $violation): ?string => is_scalar($violation) ? trim((string) $violation) : null,
                (array) data_get($event, 'payload.violations', []),
            ))),
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $events
     * @return array<string,mixed>
     */
    private function kernelPipelineEventSummary(Collection $events): array
    {
        $latest = $events->last();
        $eventCount = $events->count();
        $acceptedCount = $events->where('event_type', LedgerEventType::KernelPipelineAccepted->value)->count();
        $rejectedCount = $events->where('event_type', LedgerEventType::KernelPipelineRejected->value)->count();
        $health = $this->kernelPipelineHealth($eventCount, $acceptedCount, $rejectedCount);
        $reviewSignal = $this->kernelPipelineReviewSignal($health, $events->pluck('violations')->flatten()->filter()->countBy()->all());

        return [
            'kernel_pipeline_event_count' => $eventCount,
            'accepted_count' => $acceptedCount,
            'rejected_count' => $rejectedCount,
            'status_counts' => $events->pluck('status')->filter()->countBy()->all(),
            'surface_counts' => $events->pluck('surface_id')->filter()->countBy()->all(),
            'emitter_stage_counts' => $events->pluck('emitter_stage')->filter()->countBy()->all(),
            'surface_contract_source_counts' => $events->pluck('surface_contract_source')->filter()->countBy()->all(),
            'flow_counts' => $events->pluck('flow')->filter()->countBy()->all(),
            'input_mode_counts' => $events->pluck('input_mode')->filter()->countBy()->all(),
            'violation_counts' => $events->pluck('violations')->flatten()->filter()->countBy()->all(),
            'latest_status' => is_array($latest) ? ($latest['status'] ?? null) : null,
            'has_rejections' => $events->contains(fn (array $event): bool => ($event['status'] ?? null) === 'rejected'),
            'health' => $health,
            'review_signal' => $reviewSignal,
            'events' => $events->all(),
        ];
    }

    /**
     * @return array{
     *     status:string,
     *     rejection_rate:float,
     *     accepted_count:int,
     *     rejected_count:int,
     *     event_count:int,
     *     review_required:bool,
     *     thresholds:array{warning_rejection_rate:float,breach_rejection_rate:float},
     *     reasons:array<int,string>
     * }
     */
    private function kernelPipelineHealth(int $eventCount, int $acceptedCount, int $rejectedCount): array
    {
        $warningThreshold = 0.000001;
        $breachThreshold = 0.05;
        $rejectionRate = $eventCount > 0 ? round($rejectedCount / $eventCount, 6) : 0.0;
        $reasons = [];

        if ($eventCount === 0) {
            $status = 'unknown';
            $reasons[] = 'no_kernel_pipeline_events_in_window';
        } elseif ($rejectionRate >= $breachThreshold) {
            $status = 'breach';
            $reasons[] = 'kernel_pipeline_rejection_rate_above_breach_threshold';
        } elseif ($rejectionRate >= $warningThreshold) {
            $status = 'warning';
            $reasons[] = 'kernel_pipeline_rejections_detected';
        } else {
            $status = 'ok';
        }

        return [
            'status' => $status,
            'rejection_rate' => $rejectionRate,
            'accepted_count' => $acceptedCount,
            'rejected_count' => $rejectedCount,
            'event_count' => $eventCount,
            'review_required' => in_array($status, ['warning', 'breach'], true),
            'thresholds' => [
                'warning_rejection_rate' => $warningThreshold,
                'breach_rejection_rate' => $breachThreshold,
            ],
            'reasons' => $reasons,
        ];
    }

    /**
     * @param  array<string,mixed>  $health
     * @param  array<string,int>  $violationCounts
     * @return array{status:string,severity:string,review_required:bool,reasons:array<int,string>,recommended_action:string}
     */
    private function kernelPipelineReviewSignal(array $health, array $violationCounts): array
    {
        $status = (string) ($health['status'] ?? 'unknown');
        $reasons = array_values((array) ($health['reasons'] ?? []));
        if ($violationCounts !== []) {
            $reasons = array_values(array_unique([...$reasons, ...array_keys($violationCounts)]));
        }

        if ($status === 'unknown') {
            return [
                'status' => 'unknown',
                'severity' => 'low',
                'review_required' => false,
                'reasons' => $reasons === [] ? ['no_kernel_pipeline_events_in_window'] : $reasons,
                'recommended_action' => 'wait_for_kernel_pipeline_evidence',
            ];
        }

        if ($status === 'ok') {
            return [
                'status' => 'ok',
                'severity' => 'none',
                'review_required' => false,
                'reasons' => [],
                'recommended_action' => 'none',
            ];
        }

        return [
            'status' => $status,
            'severity' => $status === 'breach' ? 'high' : 'medium',
            'review_required' => true,
            'reasons' => $reasons === [] ? ['kernel_pipeline_rejections_detected'] : $reasons,
            'recommended_action' => 'open_reviewable_kernel_pipeline_contract_proposal',
        ];
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<string,string>
     */
    private function normalizedKernelPipelineFilters(array $filters): array
    {
        $allowed = ['status', 'surface_id', 'flow', 'input_mode', 'surface_contract_source', 'emitter_stage'];
        $normalized = [];

        foreach ($allowed as $key) {
            $value = $filters[$key] ?? null;
            if (! is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);
            if ($value !== '') {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $event
     * @param  array<string,string>  $filters
     */
    private function matchesKernelPipelineFilters(array $event, array $filters): bool
    {
        foreach ($filters as $key => $expected) {
            if ((string) data_get($event, $key, '') !== $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<string,string>
     */
    private function normalizedRepairFilters(array $filters): array
    {
        $allowed = ['status', 'strategy', 'failure_domain', 'emitter_stage'];
        $normalized = [];

        foreach ($allowed as $key) {
            $value = $filters[$key] ?? null;
            if (! is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);
            if ($value !== '') {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $event
     * @param  array<string,string>  $filters
     */
    private function matchesRepairFilters(array $event, array $filters): bool
    {
        foreach ($filters as $key => $expected) {
            if ((string) data_get($event, $key, '') !== $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<string,string>
     */
    private function normalizedInboxActionFilters(array $filters): array
    {
        $allowed = ['action', 'actor_type', 'inbox_item_category', 'inbox_item_severity', 'recommended_action', 'source_type'];
        $normalized = [];

        foreach ($allowed as $key) {
            $value = $filters[$key] ?? null;
            if (! is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);
            if ($value !== '') {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $event
     * @param  array<string,string>  $filters
     */
    private function matchesInboxActionFilters(array $event, array $filters): bool
    {
        foreach ($filters as $key => $expected) {
            if ((string) data_get($event, $key, '') !== $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $observations
     * @return array<string,mixed>
     */
    private function sloStageSummary(Collection $observations): array
    {
        $durations = $observations
            ->pluck('duration_ms')
            ->map(fn (mixed $duration): int => (int) $duration)
            ->sort()
            ->values()
            ->all();

        return [
            'count' => $observations->count(),
            'success_count' => $observations->where('success', true)->count(),
            'failure_count' => $observations->where('success', false)->count(),
            'status_counts' => $observations->countBy('status')->all(),
            'worst_status' => $this->worstSloValue($observations->pluck('status')->all(), [
                'breach' => 4,
                'warning' => 3,
                'ok' => 2,
                'unknown' => 1,
            ]),
            'worst_severity' => $this->worstSloValue($observations->pluck('severity')->all(), [
                'critical' => 5,
                'high' => 4,
                'medium' => 3,
                'low' => 2,
                'unknown' => 1,
            ]),
            'p50_ms' => $this->percentile($durations, 50),
            'p95_ms' => $this->percentile($durations, 95),
            'max_ms' => $durations === [] ? 0 : max($durations),
            'violations' => $observations
                ->pluck('violations')
                ->flatten()
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'dimensions' => $this->dimensionSummary($observations),
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $observations
     * @return array<string,array<string,int>>
     */
    private function dimensionSummary(Collection $observations): array
    {
        $summary = [];

        foreach (['domain', 'flow', 'surface_id', 'provider', 'model', 'runtime', 'tool_id'] as $dimension) {
            $counts = $observations
                ->map(fn (array $observation): ?string => data_get($observation, "dimensions.{$dimension}"))
                ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
                ->countBy()
                ->sortDesc()
                ->all();

            if ($counts !== []) {
                $summary[$dimension] = $counts;
            }
        }

        return $summary;
    }

    /**
     * @param  array<string,mixed>  $dimensions
     * @return array<string,string>
     */
    private function normalizedDimensions(array $dimensions): array
    {
        $normalized = [];

        foreach ($dimensions as $key => $value) {
            if (! is_string($key) || ! is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<string,string>
     */
    private function normalizedDimensionFilters(array $filters): array
    {
        $allowed = ['domain', 'flow', 'surface_id', 'provider', 'model', 'runtime', 'tool_id'];
        $normalized = [];

        foreach ($allowed as $key) {
            $value = $filters[$key] ?? null;
            if (! is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);
            if ($value !== '') {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $observation
     * @param  array<string,string>  $filters
     */
    private function matchesDimensionFilters(array $observation, array $filters): bool
    {
        foreach ($filters as $key => $expected) {
            if ((string) data_get($observation, "dimensions.{$key}", '') !== $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int,int>  $sortedDurations
     */
    private function percentile(array $sortedDurations, int $percentile): int
    {
        if ($sortedDurations === []) {
            return 0;
        }

        $index = (int) ceil(($percentile / 100) * count($sortedDurations)) - 1;

        return $sortedDurations[max(0, min(count($sortedDurations) - 1, $index))];
    }

    /**
     * @param  array<int,string>  $values
     * @param  array<string,int>  $rank
     */
    private function worstSloValue(array $values, array $rank): ?string
    {
        $worst = null;
        $worstRank = 0;

        foreach ($values as $value) {
            $candidateRank = $rank[$value] ?? $rank['unknown'] ?? 0;
            if ($candidateRank > $worstRank) {
                $worst = $value;
                $worstRank = $candidateRank;
            }
        }

        return $worst;
    }
}
