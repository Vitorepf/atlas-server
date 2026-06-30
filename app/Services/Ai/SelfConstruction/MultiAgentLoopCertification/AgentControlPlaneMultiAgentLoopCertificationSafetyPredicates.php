<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\MultiAgentLoopCertification;

/**
 * Pure safety/predicate helpers for the multi-agent loop certification service.
 *
 * Extracted from AgentControlPlaneMultiAgentLoopCertificationService to reduce
 * the god-class. All methods are pure — no instance state.
 */
final class AgentControlPlaneMultiAgentLoopCertificationSafetyPredicates
{
    /**
     * @return list<string>
     */
    public static function flattenStrings(mixed $value): array
    {
        $strings = [];
        if (is_string($value)) {
            return $value === '' ? [] : [$value];
        }
        if (! is_array($value)) {
            return [];
        }

        foreach ($value as $item) {
            foreach (self::flattenStrings($item) as $string) {
                $strings[] = $string;
            }
        }

        return array_values(array_unique($strings));
    }

    /**
     * @param  list<array<int, string>>  $writeSets
     */
    public static function writeSetCollisionCount(array $writeSets): int
    {
        $collisions = 0;
        $seen = [];
        foreach ($writeSets as $writeSet) {
            $normalized = array_values(array_unique(array_filter(
                array_map(static fn (mixed $p): string => self::normalizePath((string) $p), $writeSet),
                static fn (string $s): bool => $s !== ''
            )));
            if (array_intersect($seen, $normalized) !== []) {
                $collisions++;
            }
            $seen = array_values(array_unique(array_merge($seen, $normalized)));
        }

        return $collisions;
    }

    private static function normalizePath(string $path): string
    {
        $p = str_replace('\\', '/', trim($path));
        $p = (string) preg_replace('#/+#', '/', $p);
        $p = ltrim($p, '/');
        if (str_starts_with($p, './')) {
            $p = substr($p, 2);
        }

        return rtrim($p, '/');
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    public static function terminalBootstrapRuntimeSafety(array $results): bool
    {
        foreach ($results as $result) {
            foreach (['runtime_execution_allowed', 'dispatch_allowed', 'provider_call_allowed', 'token_spend_allowed', 'self_programming_allowed'] as $flag) {
                if (($result[$flag] ?? true) !== false) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $cycles
     */
    public static function confirmCompletedNeverReclaimed(array $cycles): bool
    {
        foreach ($cycles as $cycle) {
            if (! ($cycle['reclaim_completed_blocked'] ?? false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $cycles
     */
    public static function confirmNoLegacyReservationUsed(array $cycles): bool
    {
        foreach ($cycles as $cycle) {
            foreach ((array) ($cycle['agents'] ?? []) as $agent) {
                if (! in_array((string) ($agent['claim_event'] ?? ''), ['claimed', 'no_claimable_task'], true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  array<string, bool>  $queueFlags
     * @param  array<string, bool>  $leaseFlags
     */
    public static function runtimeSafetyAllFalse(array $queueFlags, array $leaseFlags): bool
    {
        foreach (['runtime_execution_allowed', 'dispatch_allowed', 'provider_call_allowed', 'token_spend_allowed', 'self_programming_allowed', 'ledger_write_allowed', 'completion_real_allowed'] as $flag) {
            if (($queueFlags[$flag] ?? true) !== false || ($leaseFlags[$flag] ?? true) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $cycleEvidence
     */
    public static function allCyclesTrue(array $cycleEvidence, string $key): bool
    {
        if ($cycleEvidence === []) {
            return false;
        }
        foreach ($cycleEvidence as $cycle) {
            if (! array_key_exists($key, $cycle)) {
                continue;
            }
            if ((bool) $cycle[$key] !== true) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, bool>  $invariants
     * @param  list<array<string, mixed>>  $cycleEvidence
     */
    public static function safeForParallelTerminalLoop(array $invariants, array $cycleEvidence): bool
    {
        $core = [
            'no_duplicate_claims',
            'no_cross_agent_completion',
            'complete_dry_run_requires_queue_claim_binding',
            'recovery_never_reopens_completed',
            'no_legacy_reservation_used',
            'runtime_safety_all_false',
            'queue_transition_policy_enforced',
            'terminal_worker_bootstrap_resumption_contract_present',
            'terminal_worker_bootstrap_resumption_checkpoint_present',
            'terminal_worker_bootstrap_iteration_runbook_present',
            'terminal_worker_bootstrap_shell_recipe_present',
            'terminal_loop_health_digest_present',
            'terminal_loop_fleet_launch_plan_present',
            'terminal_loop_fleet_launch_plan_ready_path_verified',
            'terminal_loop_fleet_partial_supply_launch_blocked',
            'terminal_loop_fleet_replenishment_plan_present',
            'terminal_loop_fleet_resume_rollup_present',
            'terminal_loop_fleet_resume_recovery_path_verified',
            'terminal_loop_fleet_metadata_orphan_recovery_verified',
            'terminal_loop_fleet_released_task_requeue_verified',
            'terminal_loop_fleet_evidence_rollup_present',
            'terminal_loop_fleet_evidence_rollup_green_path_verified',
            'terminal_loop_fleet_operator_handoff_present',
            'terminal_loop_fleet_operator_handoff_recovery_priority_verified',
            'terminal_loop_fleet_lane_isolation_present',
            'terminal_loop_fleet_lane_bound_commands_verified',
            'terminal_loop_fleet_lane_no_cross_lane_launch_verified',
            'terminal_loop_cycle_supervisor_present',
            'terminal_loop_cycle_supervisor_launch_path_verified',
            'terminal_loop_cycle_supervisor_evidence_review_path_verified',
            'terminal_loop_fleet_launch_runbook_present',
            'terminal_loop_fleet_launch_runbook_ready_path_verified',
            'certification_cleanup_leaves_no_recoverable_terminal_loop_artifacts',
            'terminal_worker_bootstrap_rejects_invalid_worker_scope',
            'terminal_worker_bootstrap_completion_evidence_template_present',
            'completion_evidence_files_within_scope',
            'terminal_worker_bootstrap_operator_commands_present',
            'terminal_worker_bootstrap_preview_read_only',
            'terminal_worker_bootstrap_partial_supply_blocks_before_claim',
            'evidence_hash_present',
        ];
        foreach ($core as $key) {
            if (! ($invariants[$key] ?? false)) {
                return false;
            }
        }
        foreach ($cycleEvidence as $cycle) {
            if ((int) ($cycle['write_set_collision_count'] ?? 0) > 0) {
                return false;
            }
            $expected = is_array($cycle['agents'] ?? null) ? count($cycle['agents']) : 0;
            if ((int) ($cycle['distinct_task_count'] ?? 0) < $expected) {
                return false;
            }
            if ((int) ($cycle['distinct_lease_count'] ?? 0) < $expected) {
                return false;
            }
        }

        return true;
    }
}
