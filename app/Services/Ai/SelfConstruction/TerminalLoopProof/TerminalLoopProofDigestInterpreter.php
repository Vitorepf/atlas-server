<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TerminalLoopProof;

/**
 * Digest-interpretation helpers for the Agent Control Plane terminal-loop
 * operational proof service.
 *
 * Extracted from AgentControlPlaneTerminalLoopOperationalProofService to
 * reduce the god-class. All methods are pure static.
 */
final class TerminalLoopProofDigestInterpreter
{
    /** @param array<string, mixed> $digest */
    public static function digestSummary(array $digest): array
    {
        return [
            'status' => (string) ($digest['status'] ?? ''),
            'claimable_task_count' => (int) data_get($digest, 'queue_health.claimable_task_count', 0),
            'claimed_task_count' => (int) data_get($digest, 'queue_health.claimed_task_count', 0),
            'active_lease_count' => (int) data_get($digest, 'lease_health.active_lease_count', 0),
            'recoverable_lease_count' => (int) data_get($digest, 'lease_health.recoverable_lease_count', 0),
            'evidence_rollup_status' => (string) data_get($digest, 'terminal_loop_fleet_evidence_rollup.status', ''),
            'completed_dry_run_task_count' => (int) data_get($digest, 'terminal_loop_fleet_evidence_rollup.completed_dry_run_task_count', 0),
            'valid_completion_evidence_count' => (int) data_get($digest, 'terminal_loop_fleet_evidence_rollup.valid_completion_evidence_count', 0),
            'cycle_supervisor_status' => (string) data_get($digest, 'terminal_loop_cycle_supervisor.status', ''),
            'cycle_supervisor_cycle_state' => (string) data_get($digest, 'terminal_loop_cycle_supervisor.cycle_state', ''),
            'cycle_supervisor_next_command_purpose' => (string) data_get($digest, 'terminal_loop_cycle_supervisor.next_command_purpose', ''),
            'cycle_supervisor_hash' => (string) data_get($digest, 'terminal_loop_cycle_supervisor.terminal_loop_cycle_supervisor_hash', ''),
            'digest_hash' => (string) data_get($digest, 'terminal_loop_health_digest_hash', ''),
        ];
    }

    /** @param array<string, mixed> $completion */
    public static function runtimeSafetyAllFalse(array $completion, array $digest): bool
    {
        return (bool) data_get($completion, 'completion_real_allowed', true) === false
            && (bool) data_get($completion, 'runtime_execution_allowed', true) === false
            && (bool) data_get($completion, 'dispatch_allowed', true) === false
            && (bool) data_get($completion, 'provider_call_allowed', true) === false
            && (bool) data_get($completion, 'token_spend_allowed', true) === false
            && (bool) data_get($completion, 'self_programming_allowed', true) === false
            && (bool) data_get($digest, 'runtime_safety.runtime_execution_allowed', true) === false
            && (bool) data_get($digest, 'runtime_safety.dispatch_allowed', true) === false
            && (bool) data_get($digest, 'runtime_safety.provider_call_allowed', true) === false
            && (bool) data_get($digest, 'runtime_safety.token_spend_allowed', true) === false
            && (bool) data_get($digest, 'runtime_safety.self_programming_allowed', true) === false;
    }
}