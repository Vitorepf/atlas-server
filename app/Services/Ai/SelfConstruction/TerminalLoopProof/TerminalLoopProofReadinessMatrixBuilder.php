<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TerminalLoopProof;

/**
 * Operational readiness matrix assembly for the Agent Control Plane
 * terminal-loop operational proof.
 *
 * Extracted from AgentControlPlaneTerminalLoopOperationalProofService to
 * reduce the god-class. All methods are pure static.
 */
final class TerminalLoopProofReadinessMatrixBuilder
{
    /**
     * @param  array<string, bool>  $invariants
     * @param  array<string, string>  $hashes
     * @return array<string, mixed>
     */
    public static function operationalReadinessMatrix(array $invariants, array $hashes): array
    {
        $rows = [
            self::matrixRow(
                'auto_replenishment_claim_and_one_shot_packet',
                [
                    'before_digest_started_with_empty_lane',
                    'auto_replenishment_generated_task',
                    'bootstrap_ready_for_worker',
                    'one_shot_worker_packet_ready',
                    'claim_acquired_active_lease',
                ],
                $invariants,
                [$hashes['auto_replenishment_hash'] ?? '', $hashes['one_shot_packet_hash'] ?? ''],
            ),
            self::matrixRow(
                'structured_completion_evidence_acceptance',
                [
                    'completion_recorded_as_dry_run',
                    'structured_completion_evidence_valid',
                    'completion_evidence_hash_matches_payload',
                    'completion_evidence_files_within_scope',
                ],
                $invariants,
                [$hashes['completion_evidence_validation_hash'] ?? ''],
            ),
            self::matrixRow(
                'interruption_recovery_resume',
                [
                    'recovery_release_recorded',
                    'recovery_resume_packet_ready',
                    'recovery_requeued_task_to_claimable',
                    'recovery_reclaimed_with_fresh_lease',
                    'recovery_completed_resumed_task_as_dry_run',
                ],
                $invariants,
                [$hashes['recovery_resume_proof_hash'] ?? ''],
            ),
            self::matrixRow(
                'invalid_evidence_rejection_then_correction',
                [
                    'hash_mismatch_completion_evidence_rejected',
                    'invalid_completion_evidence_rejected',
                    'valid_completion_after_rejection_recorded',
                ],
                $invariants,
                [$hashes['validation_rejection_proof_hash'] ?? ''],
            ),
            self::matrixRow(
                'fleet_concurrency_and_cleanup',
                [
                    'fleet_concurrency_certification_available',
                    'fleet_concurrency_claims_distinct',
                    'fleet_concurrency_write_sets_disjoint',
                    'fleet_concurrency_cleanup_green',
                ],
                $invariants,
                [$hashes['fleet_concurrency_proof_hash'] ?? ''],
            ),
            self::matrixRow(
                'partial_supply_launch_gate',
                [
                    'partial_supply_launch_blocked_before_worker_start',
                    'partial_supply_replenishment_plan_ready',
                    'partial_supply_cycle_supervisor_replenishes_before_launch',
                    'partial_supply_probe_cleanup_green',
                ],
                $invariants,
                [$hashes['partial_supply_launch_gate_proof_hash'] ?? ''],
            ),
            self::matrixRow(
                'partial_supply_bootstrap_gate',
                [
                    'partial_supply_bootstrap_blocks_before_claim',
                    'partial_supply_bootstrap_preserves_queue_and_leases',
                ],
                $invariants,
                [$hashes['partial_supply_bootstrap_gate_proof_hash'] ?? ''],
            ),
            self::matrixRow(
                'next_cycle_resume_packet',
                [
                    'post_cycle_digest_can_resume_without_chat_history',
                    'resume_packet_ready_for_next_terminal',
                ],
                $invariants,
                [$hashes['resume_packet_hash'] ?? ''],
            ),
            self::matrixRow(
                'post_cycle_cycle_supervisor_evidence_review',
                [
                    'post_cycle_cycle_supervisor_reviews_evidence',
                ],
                $invariants,
                [$hashes['post_cycle_cycle_supervisor_hash'] ?? ''],
            ),
            self::matrixRow(
                'post_cycle_end_to_end_loop_contract',
                [
                    'post_cycle_end_to_end_contract_available',
                    'post_cycle_end_to_end_contract_covers_required_loop_surfaces',
                    'post_cycle_end_to_end_contract_is_read_only',
                ],
                $invariants,
                [$hashes['post_cycle_end_to_end_contract_hash'] ?? ''],
            ),
            self::matrixRow(
                'post_cycle_health_and_runtime_safety',
                [
                    'lease_closed_after_completion',
                    'no_claimed_task_after_completion',
                    'no_recoverable_lease_after_completion',
                    'post_cycle_evidence_rollup_green',
                    'completion_real_allowed_false',
                    'runtime_safety_all_false',
                ],
                $invariants,
                [$hashes['post_cycle_health_digest_hash'] ?? ''],
            ),
        ];
        $failedRows = array_values(array_filter($rows, static fn (array $row): bool => ! (bool) ($row['passed'] ?? false)));

        return [
            'schema_version' => 'atlas.self_construction.agent_control_plane_terminal_loop_operational_readiness_matrix.v1',
            'row_count' => count($rows),
            'passed_row_count' => count($rows) - count($failedRows),
            'failed_row_count' => count($failedRows),
            'all_true' => $failedRows === [],
            'rows' => $rows,
            'failed_rows' => array_map(static fn (array $row): string => (string) $row['id'], $failedRows),
        ];
    }

    /**
     * @param  list<string>  $requiredInvariants
     * @param  array<string, bool>  $invariants
     * @param  list<string>  $evidenceHashes
     * @return array<string, mixed>
     */
    public static function matrixRow(string $id, array $requiredInvariants, array $invariants, array $evidenceHashes): array
    {
        $missing = array_values(array_filter(
            $requiredInvariants,
            static fn (string $key): bool => ! (bool) ($invariants[$key] ?? false),
        ));
        $hashes = array_values(array_filter(
            $evidenceHashes,
            static fn (string $hash): bool => preg_match('/^[a-f0-9]{64}$/', $hash) === 1,
        ));

        return [
            'id' => $id,
            'passed' => $missing === [] && $hashes !== [],
            'required_invariants' => $requiredInvariants,
            'missing_invariants' => $missing,
            'evidence_hashes' => $hashes,
        ];
    }
}