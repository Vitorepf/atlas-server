<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure postmortem miner. Converts a single autonomy-failure incident (malformed batch, lease
 * mismatch, poison re-serve, weak-green commit, quota farming) into a reusable prevention fact —
 * so every failure feeds the next brain cycle instead of repeating silently.
 *
 * Input shape: {incident_type:string, tokens_spent?:int, detail?:array<string,mixed>}
 *
 * Pure — no I/O, no provider calls, no enqueue.
 */
final class AtlasExternalBrainAutonomyIncidentPostmortemMiner
{
    public const SCHEMA = 'atlas.self_construction.external_brain.autonomy_incident_postmortem_miner.v1';

    public const TYPE_MALFORMED_BATCH = 'malformed_batch';

    public const TYPE_LEASE_MISMATCH = 'lease_mismatch';

    public const TYPE_POISON_RESERVE = 'poison_reserve';

    public const TYPE_WEAK_GREEN_COMMIT = 'weak_green_commit';

    public const TYPE_QUOTA_FARMING = 'quota_farming';

    /** Deterministic default wasted-token estimate per incident type when tokens_spent is absent. */
    private const DEFAULT_WASTED_TOKENS = [
        self::TYPE_MALFORMED_BATCH => 500,
        self::TYPE_LEASE_MISMATCH => 200,
        self::TYPE_POISON_RESERVE => 800,
        self::TYPE_WEAK_GREEN_COMMIT => 1500,
        self::TYPE_QUOTA_FARMING => 3000,
    ];

    /**
     * @param  array<string,mixed>  $incident
     * @return array{schema:string, incident_type:string, root_cause:string, detection_signal:string, wasted_token_risk:int, prevention_guard:list<string>, recommended_task_family:string, serving_impact:string, task_quality_impact:string}
     */
    public function mine(array $incident): array
    {
        $incidentType = trim((string) ($incident['incident_type'] ?? ''));
        $tokensSpent = isset($incident['tokens_spent']) ? max(0, (int) $incident['tokens_spent']) : null;

        $facts = $this->factsForType($incidentType);

        $wastedTokenRisk = $tokensSpent ?? (self::DEFAULT_WASTED_TOKENS[$incidentType] ?? 100);

        return [
            'schema' => self::SCHEMA,
            'incident_type' => $incidentType,
            'root_cause' => $facts['root_cause'],
            'detection_signal' => $facts['detection_signal'],
            'wasted_token_risk' => $wastedTokenRisk,
            'prevention_guard' => $facts['prevention_guard'],
            'recommended_task_family' => $facts['recommended_task_family'],
            'serving_impact' => $facts['serving_impact'],
            'task_quality_impact' => $facts['task_quality_impact'],
            'recurrence_risk' => $facts['recurrence_risk'],
            'next_policy_update' => $facts['next_policy_update'],
        ];
    }

    /**
     * @return array{root_cause:string, detection_signal:string, prevention_guard:list<string>, recommended_task_family:string, serving_impact:string, task_quality_impact:string, recurrence_risk:string, next_policy_update:string}
     */
    private function factsForType(string $incidentType): array
    {
        return match ($incidentType) {
            self::TYPE_MALFORMED_BATCH => [
                'root_cause' => 'packet_schema_violation',
                'detection_signal' => 'schema_validation_failed',
                'prevention_guard' => ['packet_schema_validation_gate_before_serving'],
                'recommended_task_family' => 'schema_validation_hardening',
                'serving_impact' => 'malformed_batch_blocks_serving_until_swept',
                'task_quality_impact' => 'none_packet_never_reached_a_worker',
                'recurrence_risk' => 'medium',
                'next_policy_update' => 'add_packet_schema_validation_gate_before_serving',
            ],
            self::TYPE_LEASE_MISMATCH => [
                'root_cause' => 'lease_id_stale_or_mismatched',
                'detection_signal' => 'lease_verification_failed_on_report',
                'prevention_guard' => ['enforce_lease_id_match_at_claim_and_report_time'],
                'recommended_task_family' => 'lease_lifecycle_hardening',
                // Serving-layer and task-quality impact are explicitly distinct here: a lease
                // mismatch is a serving/contention failure, never evidence the underlying task is bad.
                'serving_impact' => 'lease_contention_delays_serving_throughput',
                'task_quality_impact' => 'none_task_quality_unaffected',
                'recurrence_risk' => 'low',
                'next_policy_update' => 'enforce_lease_id_match_at_claim_and_report_time',
            ],
            self::TYPE_POISON_RESERVE => [
                'root_cause' => 'poisoned_packet_reserved_without_quarantine',
                'detection_signal' => 'repeated_give_back_on_same_packet_id',
                'prevention_guard' => ['quarantine_after_n_give_backs', 'block_re_serve_of_quarantined_packet'],
                'recommended_task_family' => 'poison_quarantine_hardening',
                'serving_impact' => 'wastes_worker_lease_cycles_on_unfixable_packet',
                'task_quality_impact' => 'task_was_never_completable_as_scoped',
                'recurrence_risk' => 'high',
                'next_policy_update' => 'quarantine_after_n_give_backs_and_block_re_serve',
            ],
            self::TYPE_WEAK_GREEN_COMMIT => [
                'root_cause' => 'tests_pass_without_behavior_assertion',
                'detection_signal' => 'weak_acceptance_pattern_detected_in_committed_test',
                'prevention_guard' => ['require_behavior_assertion_not_just_exit_zero'],
                'recommended_task_family' => 'acceptance_quality_gate_hardening',
                'serving_impact' => 'none_packet_served_and_committed_successfully',
                'task_quality_impact' => 'green_signal_did_not_prove_real_behavior',
                'recurrence_risk' => 'high',
                'next_policy_update' => 'require_behavior_assertion_in_acceptance_gate_not_just_exit_zero',
            ],
            self::TYPE_QUOTA_FARMING => [
                'root_cause' => 'high_task_count_with_no_real_leverage_evidence',
                'detection_signal' => 'task_volume_spike_without_leverage_evidence',
                'prevention_guard' => [
                    'anti_template_diversity_check',
                    'require_value_proof_per_task',
                ],
                'recommended_task_family' => 'quota_farming_detection_hardening',
                'serving_impact' => 'crowds_out_real_work_in_the_queue',
                'task_quality_impact' => 'volume_without_leverage_does_not_advance_the_scope',
                'recurrence_risk' => 'high',
                'next_policy_update' => 'require_value_proof_per_task_and_enforce_template_diversity_check',
            ],
            default => [
                'root_cause' => 'unclassified_incident',
                'detection_signal' => 'no_known_detection_signal_for_incident_type',
                'prevention_guard' => ['classify_incident_type_before_mining'],
                'recommended_task_family' => 'incident_taxonomy_hardening',
                'serving_impact' => 'unknown',
                'task_quality_impact' => 'unknown',
                'recurrence_risk' => 'unknown',
                'next_policy_update' => 'classify_incident_type_and_add_it_to_the_known_taxonomy',
            ],
        };
    }
}
