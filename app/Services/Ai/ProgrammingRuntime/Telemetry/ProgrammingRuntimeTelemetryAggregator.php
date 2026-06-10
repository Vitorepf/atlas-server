<?php

namespace App\Services\Ai\ProgrammingRuntime\Telemetry;

use App\Models\AiProgrammingRuntimeTelemetryEvent;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonImmutable;

/**
 * Read model over `ai_programming_runtime_telemetry_events`. Produces a
 * deterministic, JSON-stable shape for IA + humans:
 *
 *   - by_flow, by_core (dev/forge/dev_to_forge), by_status, by_blocker_bucket
 *   - by_evidence_completeness_bucket, by_repair_count_bucket
 *   - latency/cost summary (when present)
 *   - top_events, top_flows
 *   - claim_policy.benchmark_not_run = true (invariant)
 *
 * The aggregator NEVER runs a benchmark, NEVER compares Atlas against rival
 * providers and NEVER mutates events. It only reads.
 */
class ProgrammingRuntimeTelemetryAggregator
{
    /**
     * @return array<string,mixed>
     */
    public function aggregate(?CarbonImmutable $since = null, ?CarbonImmutable $until = null): array
    {
        $now = CarbonImmutable::now();

        if (! DatabaseTableAvailability::has('ai_programming_runtime_telemetry_events')) {
            return $this->emptyAggregate($now, $since, $until, 'telemetry_table_missing');
        }

        $query = AiProgrammingRuntimeTelemetryEvent::query();
        if ($since !== null) {
            $query->where('created_at', '>=', $since);
        }
        if ($until !== null) {
            $query->where('created_at', '<=', $until);
        }
        $events = $query->orderBy('created_at')->get();
        $total = $events->count();

        if ($total === 0) {
            return $this->emptyAggregate($now, $since, $until, 'no_events_in_window');
        }

        return [
            'schema_version' => ProgrammingRuntimeTelemetryCanon::AGGREGATE_SCHEMA_VERSION,
            'generated_at' => $now->toISOString(),
            'window' => $this->window($since, $until, $events->first()?->created_at, $events->last()?->created_at),
            'total_events' => $total,
            'distinct_runs' => $events->pluck('run_id')->filter()->unique()->count(),
            'distinct_missions' => $events->pluck('mission_id')->filter()->unique()->count(),
            'distinct_obras' => $events->pluck('obra_id')->filter()->unique()->count(),
            'by_event_name' => $this->countBy($events, fn ($e) => $e->event_name),
            'by_flow' => $this->countBy($events, fn ($e) => $e->flow ?? 'unspecified'),
            'by_core' => $this->countBy($events, fn ($e) => $e->selected_core ?? 'unspecified'),
            'by_execution_status' => $this->countBy($events, fn ($e) => $e->execution_status ?? 'unspecified'),
            'by_test_status' => $this->countBy($events, fn ($e) => $e->test_status ?? 'unspecified'),
            'by_rag_gate_status' => $this->countBy($events, fn ($e) => $e->rag_gate_status ?? 'unspecified'),
            'by_certification_status' => $this->countBy($events, fn ($e) => $e->certification_status ?? 'unspecified'),
            'by_blocker_bucket' => $this->countBy($events, fn ($e) => $this->blockerBucket($e->blocker_count)),
            'by_evidence_completeness_bucket' => $this->countBy(
                $events,
                fn ($e) => $this->scoreBucket($e->evidence_completeness),
            ),
            'by_context_sufficiency_bucket' => $this->countBy(
                $events,
                fn ($e) => $this->scoreBucket($e->context_sufficiency),
            ),
            'by_repair_count_bucket' => $this->countBy(
                $events,
                fn ($e) => $this->repairBucket($e->repair_attempt_count),
            ),
            'duration_summary' => $this->numericSummary($events->pluck('duration_ms')),
            'cost_summary' => $this->numericSummary($events->pluck('cost_estimate_usd')),
            'evidence_completeness_summary' => $this->numericSummary($events->pluck('evidence_completeness')),
            'context_sufficiency_summary' => $this->numericSummary($events->pluck('context_sufficiency')),
            'claim_policy' => [
                'benchmark_not_run' => true,
                'rivals_compared' => false,
                'allows_internal_measurement_claim' => true,
                'allows_external_superiority_claim' => false,
                'next_step_to_unlock_benchmark' => 'human_authorised_external_battery',
            ],
            'note' => 'Internal Programming Runtime telemetry. NOT a benchmark.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyAggregate(CarbonImmutable $now, ?CarbonImmutable $since, ?CarbonImmutable $until, string $reason): array
    {
        return [
            'schema_version' => ProgrammingRuntimeTelemetryCanon::AGGREGATE_SCHEMA_VERSION,
            'generated_at' => $now->toISOString(),
            'window' => $this->window($since, $until, null, null),
            'total_events' => 0,
            'distinct_runs' => 0,
            'distinct_missions' => 0,
            'distinct_obras' => 0,
            'by_event_name' => [],
            'by_flow' => [],
            'by_core' => [],
            'by_execution_status' => [],
            'by_test_status' => [],
            'by_rag_gate_status' => [],
            'by_certification_status' => [],
            'by_blocker_bucket' => [],
            'by_evidence_completeness_bucket' => [],
            'by_context_sufficiency_bucket' => [],
            'by_repair_count_bucket' => [],
            'duration_summary' => $this->numericSummary(collect()),
            'cost_summary' => $this->numericSummary(collect()),
            'evidence_completeness_summary' => $this->numericSummary(collect()),
            'context_sufficiency_summary' => $this->numericSummary(collect()),
            'claim_policy' => [
                'benchmark_not_run' => true,
                'rivals_compared' => false,
                'allows_internal_measurement_claim' => false,
                'allows_external_superiority_claim' => false,
                'next_step_to_unlock_benchmark' => 'human_authorised_external_battery',
            ],
            'reason' => $reason,
            'note' => 'Internal Programming Runtime telemetry. NOT a benchmark.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function window(?CarbonImmutable $since, ?CarbonImmutable $until, mixed $firstAt, mixed $lastAt): array
    {
        return [
            'since' => $since?->toISOString(),
            'until' => $until?->toISOString(),
            'first_event_at' => $this->isoOrNull($firstAt),
            'last_event_at' => $this->isoOrNull($lastAt),
        ];
    }

    private function isoOrNull(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->toISOString();
        }

        return null;
    }

    /**
     * @param  iterable<int,AiProgrammingRuntimeTelemetryEvent>  $events
     * @return array<string,int>
     */
    private function countBy(iterable $events, callable $extractor): array
    {
        $counts = [];
        foreach ($events as $event) {
            $key = (string) $extractor($event);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    /**
     * @param  iterable<int,mixed>  $values
     * @return array<string,int|float|null>
     */
    private function numericSummary(iterable $values): array
    {
        $numeric = [];
        foreach ($values as $value) {
            if (is_numeric($value)) {
                $numeric[] = (float) $value;
            }
        }
        if ($numeric === []) {
            return ['count' => 0, 'min' => null, 'max' => null, 'avg' => null];
        }
        sort($numeric);
        $count = count($numeric);
        $sum = array_sum($numeric);

        return [
            'count' => $count,
            'min' => $numeric[0],
            'max' => $numeric[$count - 1],
            'avg' => round($sum / $count, 4),
        ];
    }

    private function blockerBucket(?int $count): string
    {
        if ($count === null) {
            return 'unspecified';
        }
        if ($count === 0) {
            return '0';
        }
        if ($count <= 2) {
            return '1-2';
        }
        if ($count <= 5) {
            return '3-5';
        }

        return '6+';
    }

    private function scoreBucket(?int $score): string
    {
        if ($score === null) {
            return 'unspecified';
        }
        if ($score >= 80) {
            return '80-100';
        }
        if ($score >= 60) {
            return '60-79';
        }
        if ($score >= 40) {
            return '40-59';
        }
        if ($score >= 20) {
            return '20-39';
        }

        return '0-19';
    }

    private function repairBucket(?int $count): string
    {
        if ($count === null) {
            return 'unspecified';
        }
        if ($count === 0) {
            return '0';
        }
        if ($count === 1) {
            return '1';
        }
        if ($count <= 3) {
            return '2-3';
        }

        return '4+';
    }
}
