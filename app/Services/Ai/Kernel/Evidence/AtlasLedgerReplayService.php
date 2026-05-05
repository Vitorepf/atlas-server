<?php

namespace App\Services\Ai\Kernel\Evidence;

use App\Models\AtlasLedgerEvent;
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
                'repair_event_count' => 0,
                'envelope_count' => 0,
                'initiated_count' => 0,
                'completed_count' => 0,
                'executed_count' => 0,
                'status_counts' => [],
                'strategy_counts' => [],
                'reason_counts' => [],
                'latest_status' => null,
                'latest_strategy' => null,
                'requires_human_review' => false,
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
                'observation_count' => 0,
                'envelope_count' => 0,
                'status_counts' => [],
                'success_count' => 0,
                'failure_count' => 0,
                'worst_status' => null,
                'worst_severity' => null,
                'dimensions' => [],
                'stages' => [],
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
        return [
            'observation_count' => $observations->count(),
            'status_counts' => $observations->countBy('status')->all(),
            'success_count' => $observations->where('success', true)->count(),
            'failure_count' => $observations->where('success', false)->count(),
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
            'dimensions' => $this->dimensionSummary($observations),
            'stages' => $observations
                ->groupBy('stage')
                ->map(fn (Collection $stageObservations): array => $this->sloStageSummary($stageObservations))
                ->all(),
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

        return [
            'repair_event_count' => $events->count(),
            'initiated_count' => $events->where('event_type', LedgerEventType::RepairInitiated->value)->count(),
            'completed_count' => $events->where('event_type', LedgerEventType::RepairCompleted->value)->count(),
            'executed_count' => $events->where('repair_executed', true)->count(),
            'status_counts' => $events->pluck('status')->filter()->countBy()->all(),
            'strategy_counts' => $events->pluck('strategy')->filter()->countBy()->all(),
            'reason_counts' => $events->pluck('reasons')->flatten()->filter()->countBy()->all(),
            'latest_status' => is_array($latest) ? ($latest['status'] ?? null) : null,
            'latest_strategy' => is_array($latest) ? ($latest['strategy'] ?? null) : null,
            'requires_human_review' => $events->contains(fn (array $event): bool => ($event['status'] ?? null) === 'needs_human_review' || ($event['strategy'] ?? null) === 'human_review'),
            'events' => $events->all(),
        ];
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
