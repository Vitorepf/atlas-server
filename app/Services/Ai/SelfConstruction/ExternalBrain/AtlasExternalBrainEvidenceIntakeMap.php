<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Defines exactly which evidence streams the external brain may consume when originating tasks.
 *
 * Eight permitted streams (evidence-first origination):
 *   queue_health         — task queue depth, jam indicators, stall counts
 *   queued_targets       — currently queued task ids and their metadata
 *   muscle_outcomes      — worker success / give_back / proxy verdicts per task
 *   give_back_reasons    — structured give_back reasons from AtlasExternalBrainGiveBackRootCauseMiner
 *   code_facts           — symbol counts, orphan wiring, drift from code intelligence
 *   docs_drift           — documentation staleness from atlas engineering KB
 *   runtime_receipts     — Evidence Ledger append-only audit events
 *   research_source_plans — research/source plans from AtlasExternalBrainResearchPatternPlan
 *
 * REJECTED as primary evidence for task creation (unverifiable / provider-internal):
 *   chat_memory          — unreliable, unaudited, provider-session-scoped
 *   raw_provider_prompt  — private provider internals, not grounded in Atlas runtime
 *
 * PURE / DETERMINISTIC. No I/O.
 */
final class AtlasExternalBrainEvidenceIntakeMap
{
    public const SCHEMA = 'atlas.external_brain.evidence_intake_map.v1';

    /** Streams that are explicitly rejected as primary evidence sources. */
    private const REJECTED_STREAMS = ['chat_memory', 'raw_provider_prompt'];

    /**
     * Returns the full intake map.
     *
     * @return array{schema:string, streams:list<array<string,mixed>>, rejected_sources:list<string>}
     */
    public function describe(): array
    {
        return [
            'schema' => self::SCHEMA,
            'streams' => $this->streams(),
            'rejected_sources' => self::REJECTED_STREAMS,
        ];
    }

    /**
     * Validates a data record against the stream's minimum_fields contract.
     *
     * @param  array<string, mixed>  $record
     * @return array{valid:bool, stream_id:string, missing_fields:list<string>, rejected:bool}
     */
    public function validate(string $streamId, array $record): array
    {
        if (in_array($streamId, self::REJECTED_STREAMS, true)) {
            return ['valid' => false, 'stream_id' => $streamId, 'missing_fields' => [], 'rejected' => true];
        }

        $stream = $this->findStream($streamId);
        if ($stream === null) {
            return ['valid' => false, 'stream_id' => $streamId, 'missing_fields' => [], 'rejected' => false];
        }

        $missing = array_values(array_filter(
            $stream['minimum_fields'],
            static fn (string $f): bool => ! array_key_exists($f, $record)
        ));

        return ['valid' => $missing === [], 'stream_id' => $streamId, 'missing_fields' => $missing, 'rejected' => false];
    }

    /**
     * Returns true if the stream is explicitly rejected as a primary evidence source.
     */
    public function isRejected(string $streamId): bool
    {
        return in_array($streamId, self::REJECTED_STREAMS, true);
    }

    /** @return list<array<string,mixed>> */
    private function streams(): array
    {
        return [
            [
                'stream_id' => 'queue_health',
                'source_type' => 'task_serving_disk',
                'freshness_expectation' => 'real_time',
                'minimum_fields' => ['depth', 'stall_count', 'oldest_queued_at_unix'],
                'influences' => ['origination_rate', 'breakthrough_trigger', 'wave_width'],
            ],
            [
                'stream_id' => 'queued_targets',
                'source_type' => 'task_serving_disk',
                'freshness_expectation' => 'real_time',
                'minimum_fields' => ['task_packet_id', 'task_class', 'queued_at_unix'],
                'influences' => ['dedup_check', 'dependency_graph', 'lane_assignment'],
            ],
            [
                'stream_id' => 'muscle_outcomes',
                'source_type' => 'task_serving_disk',
                'freshness_expectation' => 'real_time',
                'minimum_fields' => ['task_packet_id', 'outcome', 'worker_id', 'reported_at_unix'],
                'influences' => ['outcome_learning', 'worker_affinity', 'proxy_detection'],
            ],
            [
                'stream_id' => 'give_back_reasons',
                'source_type' => 'task_serving_disk',
                'freshness_expectation' => 'real_time',
                'minimum_fields' => ['task_packet_id', 'reason', 'root_cause', 'give_back_count'],
                'influences' => ['spec_repair', 'poison_packet_detection', 'worker_weakness_routing'],
            ],
            [
                'stream_id' => 'code_facts',
                'source_type' => 'code_intelligence',
                'freshness_expectation' => 'hourly',
                'minimum_fields' => ['workspace', 'symbol_count', 'orphan_count', 'indexed_at_unix'],
                'influences' => ['wiring_gap_origination', 'capability_delta_claims', 'collision_detection'],
            ],
            [
                'stream_id' => 'docs_drift',
                'source_type' => 'docs_kb',
                'freshness_expectation' => 'daily',
                'minimum_fields' => ['doc_path', 'last_modified_unix', 'drift_score'],
                'influences' => ['thesis_generation', 'maturity_gap_index', 'acceptance_path_grounding'],
            ],
            [
                'stream_id' => 'runtime_receipts',
                'source_type' => 'evidence_ledger',
                'freshness_expectation' => 'real_time',
                'minimum_fields' => ['event_type', 'subject_id', 'recorded_at_unix', 'payload_hash'],
                'influences' => ['certification_status', 'proof_gap_index', 'breakthrough_planner'],
            ],
            [
                'stream_id' => 'research_source_plans',
                'source_type' => 'external_brain_internal',
                'freshness_expectation' => 'hourly',
                'minimum_fields' => ['plan_id', 'source_categories', 'freshness_requirements', 'created_at_unix'],
                'influences' => ['thesis_generation', 'opportunity_cluster_seeding', 'research_pattern_planning'],
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function findStream(string $streamId): ?array
    {
        foreach ($this->streams() as $stream) {
            if ($stream['stream_id'] === $streamId) {
                return $stream;
            }
        }

        return null;
    }
}
