<?php

namespace App\Services\Ai\Kernel\Evidence\LedgerReplay;

use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Support\AiStringListNormalizer;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Collection;

/**
 * Leaf projection/filter/stat helpers extracted from AtlasLedgerReplayService (GOD-DEBULK).
 * Pure and stateless (no ledger/DB access); bodies verbatim from the facade.
 */
class LedgerReplaySupport
{
    /**
     * @param  array<string,mixed>  $event
     * @return array<string,mixed>
     */
    public function sloObservationFromEvent(array $event): array
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
    public function repairEventFromEvent(array $event): array
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
            'reasons' => AiStringListNormalizer::truthyTrimmedScalarValues(
                (array) data_get($decision, 'reasons', data_get($event, 'payload.reasons', [])),
            ),
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
    public function agentBehaviorEventFromEvent(array $event): array
    {
        $payload = (array) ($event['payload'] ?? []);
        $findings = collect((array) data_get($payload, 'agent_behavior_findings', []))
            ->filter(fn (mixed $finding): bool => is_array($finding))
            ->map(fn (array $finding): array => [
                'code' => (string) ($finding['code'] ?? 'unknown'),
                'severity' => (string) ($finding['severity'] ?? 'unknown'),
                'review_signal' => data_get($finding, 'metadata.review_signal'),
                'contract_id' => data_get($finding, 'metadata.contract_id'),
                'contract_hash' => data_get($finding, 'metadata.contract_hash'),
                'principle' => data_get($finding, 'evidence.principle'),
            ])
            ->values()
            ->all();

        return [
            'event_id' => $event['event_id'] ?? null,
            'event_type' => $event['event_type'] ?? null,
            'envelope_id' => $event['envelope_id'] ?? null,
            'trace_id' => $event['trace_id'] ?? data_get($payload, 'trace.trace_id'),
            'correlation_id' => $event['correlation_id'] ?? null,
            'causation_id' => $event['causation_id'] ?? null,
            'emitter_stage' => $event['emitter_stage'] ?? null,
            'payload_hash' => $event['payload_hash'] ?? null,
            'occurred_at' => $event['occurred_at'] ?? null,
            'gate_id' => data_get($payload, 'gate_id'),
            'status' => (string) data_get($payload, 'status', 'unknown'),
            'score' => (int) data_get($payload, 'score', 0),
            'provider' => data_get($payload, 'provider'),
            'model' => data_get($payload, 'model'),
            'agent_slug' => data_get($payload, 'agent_slug'),
            'contract_id' => data_get($payload, 'contract_id'),
            'contract_hash' => data_get($payload, 'contract_hash'),
            'flag_codes' => (array) data_get($payload, 'flags', []),
            'suggested_action_codes' => (array) data_get($payload, 'suggested_actions', []),
            'finding_codes' => collect($findings)->pluck('code')->filter()->values()->all(),
            'finding_severities' => collect($findings)->pluck('severity')->filter()->values()->all(),
            'findings' => $findings,
        ];
    }

    /**
     * @param  array<string,mixed>  $event
     * @return array<string,mixed>
     */
    public function kernelPipelineEventFromEvent(array $event): array
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
            'violations' => AiStringListNormalizer::truthyTrimmedScalarValues(
                (array) data_get($event, 'payload.violations', []),
            ),
        ];
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<string,string>
     */
    public function normalizedKernelPipelineFilters(array $filters): array
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
    public function matchesKernelPipelineFilters(array $event, array $filters): bool
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
    public function normalizedRepairFilters(array $filters): array
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
    public function matchesRepairFilters(array $event, array $filters): bool
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
    public function normalizedInboxActionFilters(array $filters): array
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
    public function matchesInboxActionFilters(array $event, array $filters): bool
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
    public function normalizedAgentBehaviorFilters(array $filters): array
    {
        $allowed = ['status', 'provider', 'model', 'agent_slug', 'finding_code', 'contract_id'];
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
    public function matchesAgentBehaviorFilters(array $event, array $filters): bool
    {
        foreach ($filters as $key => $expected) {
            if ($key === 'finding_code') {
                if (! in_array($expected, (array) ($event['finding_codes'] ?? []), true)) {
                    return false;
                }

                continue;
            }

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
    public function sloStageSummary(Collection $observations): array
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
    public function dimensionSummary(Collection $observations): array
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
    public function normalizedDimensions(array $dimensions): array
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
    public function normalizedDimensionFilters(array $filters): array
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
    public function matchesDimensionFilters(array $observation, array $filters): bool
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
    public function percentile(array $sortedDurations, int $percentile): int
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
    public function worstSloValue(array $values, array $rank): ?string
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
    public function sloObservationSummary(Collection $observations): array
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

    public function sloReviewSignal(Collection $observations, ?string $worstStatus, ?string $worstSeverity, int $failureCount, array $stages): array
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

    public function repairEventSummary(Collection $events): array
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

    public function repairReviewSignal(Collection $events, array $statusCounts, array $strategyCounts, array $reasonCounts, bool $requiresHumanReview): array
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

    public function inboxActionSummary(Collection $events): array
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
        $retrievalRegressionReviewCount = $events
            ->filter(fn (array $event): bool => ($event['action'] ?? null) === 'review_retrieval_regression')
            ->count();
        $retrievalRegressionReviewedCount = $events
            ->filter(fn (array $event): bool => ($event['action'] ?? null) === 'review_retrieval_regression')
            ->filter(fn (array $event): bool => (bool) ($event['retrieval_regression_reviewed'] ?? false))
            ->count();
        $retrievalShadowScopeReviewCount = $events
            ->filter(fn (array $event): bool => ($event['action'] ?? null) === 'review_retrieval_shadow_scope')
            ->count();
        $retrievalShadowScopeReviewedCount = $events
            ->filter(fn (array $event): bool => ($event['action'] ?? null) === 'review_retrieval_shadow_scope')
            ->filter(fn (array $event): bool => (bool) ($event['retrieval_shadow_scope_reviewed'] ?? false))
            ->count();
        $retrievalShadowScopeReceiptCount = $events
            ->filter(fn (array $event): bool => ($event['action'] ?? null) === 'review_retrieval_shadow_scope')
            ->filter(fn (array $event): bool => filled($event['retrieval_shadow_scope_decision_receipt_hash'] ?? null))
            ->count();
        $retrievalShadowScopeRuntimeAllowedCount = $events
            ->filter(fn (array $event): bool => ($event['action'] ?? null) === 'review_retrieval_shadow_scope')
            ->filter(fn (array $event): bool => (bool) ($event['retrieval_shadow_scope_shadow_execution_allowed_now'] ?? false))
            ->count();
        $externalVectorRagPreflightReviewCount = $events
            ->filter(fn (array $event): bool => ($event['action'] ?? null) === 'review_external_vector_rag_preflight')
            ->count();
        $externalVectorRagPreflightReviewedCount = $events
            ->filter(fn (array $event): bool => ($event['action'] ?? null) === 'review_external_vector_rag_preflight')
            ->filter(fn (array $event): bool => (bool) ($event['external_vector_rag_preflight_reviewed'] ?? false))
            ->count();
        $externalVectorRagPreflightReceiptCount = $events
            ->filter(fn (array $event): bool => ($event['action'] ?? null) === 'review_external_vector_rag_preflight')
            ->filter(fn (array $event): bool => filled($event['external_vector_rag_preflight_decision_receipt_hash'] ?? null))
            ->count();
        $externalVectorRagPreflightUnsafeActivationCount = $events
            ->filter(fn (array $event): bool => ($event['action'] ?? null) === 'review_external_vector_rag_preflight')
            ->filter(fn (array $event): bool => (bool) ($event['external_vector_rag_preflight_embedding_allowed_now'] ?? false)
                || (bool) ($event['external_vector_rag_preflight_vector_write_allowed_now'] ?? false)
                || (bool) ($event['external_vector_rag_preflight_constellation_allowed_now'] ?? false))
            ->count();
        $reviewSignal = $this->inboxActionReviewSignal(
            $events,
            $reviewedPatchCount,
            $withDiffRefsCount,
            $rivalsReviewRecordedCount,
            $rivalsReviewWithScoresCount,
            $providerCostRateActionCount,
            $providerCostRateAppliedCount,
            $retrievalRegressionReviewCount,
            $retrievalRegressionReviewedCount,
            $retrievalShadowScopeReviewCount,
            $retrievalShadowScopeReviewedCount,
            $retrievalShadowScopeReceiptCount,
            $retrievalShadowScopeRuntimeAllowedCount,
            $externalVectorRagPreflightReviewCount,
            $externalVectorRagPreflightReviewedCount,
            $externalVectorRagPreflightReceiptCount,
            $externalVectorRagPreflightUnsafeActivationCount,
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
            'retrieval_regression_review_count' => $retrievalRegressionReviewCount,
            'retrieval_regression_reviewed_count' => $retrievalRegressionReviewedCount,
            'retrieval_regression_decision_counts' => $events
                ->filter(fn (array $event): bool => ($event['action'] ?? null) === 'review_retrieval_regression')
                ->pluck('retrieval_regression_decision')
                ->filter()
                ->countBy()
                ->all(),
            'retrieval_shadow_scope_review_count' => $retrievalShadowScopeReviewCount,
            'retrieval_shadow_scope_reviewed_count' => $retrievalShadowScopeReviewedCount,
            'retrieval_shadow_scope_decision_receipt_count' => $retrievalShadowScopeReceiptCount,
            'retrieval_shadow_scope_runtime_allowed_count' => $retrievalShadowScopeRuntimeAllowedCount,
            'retrieval_shadow_scope_decision_counts' => $events
                ->filter(fn (array $event): bool => ($event['action'] ?? null) === 'review_retrieval_shadow_scope')
                ->pluck('retrieval_shadow_scope_decision')
                ->filter()
                ->countBy()
                ->all(),
            'external_vector_rag_preflight_review_count' => $externalVectorRagPreflightReviewCount,
            'external_vector_rag_preflight_reviewed_count' => $externalVectorRagPreflightReviewedCount,
            'external_vector_rag_preflight_decision_receipt_count' => $externalVectorRagPreflightReceiptCount,
            'external_vector_rag_preflight_unsafe_activation_count' => $externalVectorRagPreflightUnsafeActivationCount,
            'external_vector_rag_preflight_decision_counts' => $events
                ->filter(fn (array $event): bool => ($event['action'] ?? null) === 'review_external_vector_rag_preflight')
                ->pluck('external_vector_rag_preflight_decision')
                ->filter()
                ->countBy()
                ->all(),
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

    public function inboxActionReviewSignal(
        Collection $events,
        int $reviewedPatchCount,
        int $withDiffRefsCount,
        int $rivalsReviewRecordedCount,
        int $rivalsReviewWithScoresCount,
        int $providerCostRateActionCount,
        int $providerCostRateAppliedCount,
        int $retrievalRegressionReviewCount,
        int $retrievalRegressionReviewedCount,
        int $retrievalShadowScopeReviewCount,
        int $retrievalShadowScopeReviewedCount,
        int $retrievalShadowScopeReceiptCount,
        int $retrievalShadowScopeRuntimeAllowedCount,
        int $externalVectorRagPreflightReviewCount,
        int $externalVectorRagPreflightReviewedCount,
        int $externalVectorRagPreflightReceiptCount,
        int $externalVectorRagPreflightUnsafeActivationCount,
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

        if ($retrievalRegressionReviewCount > 0 && $retrievalRegressionReviewedCount < $retrievalRegressionReviewCount) {
            return [
                'status' => 'warning',
                'severity' => 'medium',
                'review_required' => true,
                'reasons' => ['review_retrieval_regression_action_without_review_marker'],
                'recommended_action' => 'open_memory_retrieval_regression_review',
            ];
        }

        if ($retrievalShadowScopeRuntimeAllowedCount > 0) {
            return [
                'status' => 'warning',
                'severity' => 'high',
                'review_required' => true,
                'reasons' => ['retrieval_shadow_scope_review_allowed_runtime_execution'],
                'recommended_action' => 'review_retrieval_shadow_scope',
            ];
        }

        if ($retrievalShadowScopeReviewCount > 0 && $retrievalShadowScopeReviewedCount < $retrievalShadowScopeReviewCount) {
            return [
                'status' => 'warning',
                'severity' => 'medium',
                'review_required' => true,
                'reasons' => ['review_retrieval_shadow_scope_action_without_review_marker'],
                'recommended_action' => 'review_retrieval_shadow_scope',
            ];
        }

        if ($retrievalShadowScopeReviewCount > 0 && $retrievalShadowScopeReceiptCount < $retrievalShadowScopeReviewCount) {
            return [
                'status' => 'warning',
                'severity' => 'medium',
                'review_required' => true,
                'reasons' => ['review_retrieval_shadow_scope_action_without_decision_receipt'],
                'recommended_action' => 'review_retrieval_shadow_scope',
            ];
        }

        if ($externalVectorRagPreflightUnsafeActivationCount > 0) {
            return [
                'status' => 'warning',
                'severity' => 'high',
                'review_required' => true,
                'reasons' => ['external_vector_rag_preflight_review_allowed_unsafe_activation'],
                'recommended_action' => 'review_external_vector_rag_preflight',
            ];
        }

        if ($externalVectorRagPreflightReviewCount > 0 && $externalVectorRagPreflightReviewedCount < $externalVectorRagPreflightReviewCount) {
            return [
                'status' => 'warning',
                'severity' => 'medium',
                'review_required' => true,
                'reasons' => ['review_external_vector_rag_preflight_action_without_review_marker'],
                'recommended_action' => 'review_external_vector_rag_preflight',
            ];
        }

        if ($externalVectorRagPreflightReviewCount > 0 && $externalVectorRagPreflightReceiptCount < $externalVectorRagPreflightReviewCount) {
            return [
                'status' => 'warning',
                'severity' => 'medium',
                'review_required' => true,
                'reasons' => ['review_external_vector_rag_preflight_action_without_decision_receipt'],
                'recommended_action' => 'review_external_vector_rag_preflight',
            ];
        }

        if ($rivalsReviewRecordedCount > 0 && $rivalsReviewWithScoresCount < $rivalsReviewRecordedCount) {
            return [
                'status' => 'warning',
                'severity' => 'medium',
                'review_required' => true,
                'reasons' => ['record_rivals_review_action_without_scores'],
                'recommended_action' => 'open_reviewable_inbox_action_evidence_proposal',
            ];
        }

        if ($providerCostRateActionCount > 0 && $providerCostRateAppliedCount < $providerCostRateActionCount) {
            return [
                'status' => 'warning',
                'severity' => 'medium',
                'review_required' => true,
                'reasons' => ['configure_provider_cost_rates_action_without_applied_rate'],
                'recommended_action' => 'configure_provider_cost_rates',
            ];
        }

        if ($reviewedPatchCount > 0 && $withDiffRefsCount === 0) {
            return [
                'status' => 'warning',
                'severity' => 'medium',
                'review_required' => true,
                'reasons' => ['review_patch_action_without_diff_refs'],
                'recommended_action' => 'open_reviewable_inbox_action_evidence_proposal',
            ];
        }

        if ($retrievalShadowScopeReviewCount > 0
            && $retrievalShadowScopeReviewedCount === $retrievalShadowScopeReviewCount
            && $retrievalShadowScopeReceiptCount === $retrievalShadowScopeReviewCount
        ) {
            return [
                'status' => 'ok',
                'severity' => 'none',
                'review_required' => false,
                'reasons' => ['memory_retrieval_shadow_scope_review_recorded'],
                'recommended_action' => 'none',
            ];
        }

        if ($retrievalRegressionReviewCount > 0 && $retrievalRegressionReviewedCount === $retrievalRegressionReviewCount) {
            return [
                'status' => 'ok',
                'severity' => 'none',
                'review_required' => false,
                'reasons' => ['memory_retrieval_regression_review_recorded'],
                'recommended_action' => 'none',
            ];
        }

        if ($externalVectorRagPreflightReviewCount > 0
            && $externalVectorRagPreflightReviewedCount === $externalVectorRagPreflightReviewCount
            && $externalVectorRagPreflightReceiptCount === $externalVectorRagPreflightReviewCount
        ) {
            return [
                'status' => 'ok',
                'severity' => 'none',
                'review_required' => false,
                'reasons' => ['external_vector_rag_preflight_review_recorded'],
                'recommended_action' => 'none',
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

        if ($providerCostRateActionCount > 0 && $providerCostRateAppliedCount === $providerCostRateActionCount) {
            return [
                'status' => 'ok',
                'severity' => 'none',
                'review_required' => false,
                'reasons' => ['provider_cost_rates_configured'],
                'recommended_action' => 'none',
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

        return [
            'status' => 'ok',
            'severity' => 'none',
            'review_required' => false,
            'reasons' => [],
            'recommended_action' => 'none',
        ];
    }

    public function agentBehaviorSummary(Collection $events): array
    {
        $findingCodes = $events->pluck('finding_codes')->flatten()->filter()->values();
        $findingSeverities = $events->pluck('finding_severities')->flatten()->filter()->values();
        $scoreAvg = $events->isEmpty()
            ? null
            : round($events->pluck('score')->map(fn (mixed $score): int => (int) $score)->avg(), 2);
        $reviewSignal = $this->agentBehaviorReviewSignal($events, $findingCodes);

        return [
            'agent_behavior_event_count' => $events->count(),
            'finding_count' => $findingCodes->count(),
            'status_counts' => $events->pluck('status')->filter()->countBy()->all(),
            'finding_code_counts' => $findingCodes->countBy()->all(),
            'finding_severity_counts' => $findingSeverities->countBy()->all(),
            'provider_counts' => $events->pluck('provider')->filter()->countBy()->all(),
            'agent_slug_counts' => $events->pluck('agent_slug')->filter()->countBy()->all(),
            'average_score' => $scoreAvg,
            'review_signal' => $reviewSignal,
            'events' => $events->all(),
        ];
    }

    public function agentBehaviorReviewSignal(Collection $events, Collection $findingCodes): array
    {
        if ($events->isEmpty()) {
            return [
                'status' => 'unknown',
                'severity' => 'low',
                'review_required' => false,
                'reasons' => ['no_agent_behavior_gate_events_in_window'],
                'recommended_action' => 'wait_for_agent_behavior_evidence',
            ];
        }

        $recurring = $findingCodes
            ->countBy()
            ->filter(fn (int $count): bool => $count >= 2)
            ->keys()
            ->values()
            ->all();

        if ($recurring !== []) {
            return [
                'status' => 'warning',
                'severity' => 'medium',
                'review_required' => true,
                'reasons' => array_map(fn (string $code): string => 'recurring_agent_behavior_finding:'.$code, $recurring),
                'recommended_action' => 'open_reviewable_agent_behavior_quality_proposal',
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

    public function decisionReceiptEventSummary(Collection $events): array
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

    public function decisionReceiptReviewSignal(Collection $events, Collection $invalidEvents): array
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

    public function selfImprovementScheduleEventSummary(Collection $events): array
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
            'emitted_inbox_item_hydration_available' => DatabaseTableAvailability::has('ai_inbox_items'),
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

    public function selfImprovementScheduleReviewSignal(Collection $events, int $warningCount, array $issueCounts): array
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

}
