<?php

namespace App\Services\Ai\Kernel\Evidence\LedgerReplay;

use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Support\AiStringListNormalizer;
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

}
