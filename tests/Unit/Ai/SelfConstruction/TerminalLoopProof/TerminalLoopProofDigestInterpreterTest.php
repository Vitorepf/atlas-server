<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TerminalLoopProof;

use App\Services\Ai\SelfConstruction\TerminalLoopProof\TerminalLoopProofDigestInterpreter;
use Tests\TestCase;

final class TerminalLoopProofDigestInterpreterTest extends TestCase
{
    // ── digestSummary: empty digest ────────────────────────────────────

    public function test_digest_summary_empty(): void
    {
        $summary = TerminalLoopProofDigestInterpreter::digestSummary([]);

        self::assertSame('', $summary['status']);
        self::assertSame('depleted', $summary['supply_status']);
        self::assertSame(0, $summary['claimable_task_count']);
        self::assertSame(0, $summary['claimed_task_count']);
        self::assertFalse($summary['recoverable_backlog_present']);
        self::assertFalse($summary['evidence_ready_for_review']);
        self::assertNotEmpty($summary['safety_blockers']);
    }

    // ── digestSummary: full valid digest ────────────────────────────────

    public function test_digest_summary_full(): void
    {
        $digest = [
            'status' => 'running',
            'queue_health' => [
                'claimable_task_count' => 5,
                'claimed_task_count' => 2,
            ],
            'lease_health' => [
                'active_lease_count' => 2,
                'recoverable_lease_count' => 1,
            ],
            'terminal_loop_fleet_evidence_rollup' => [
                'status' => 'available',
                'completed_dry_run_task_count' => 3,
                'valid_completion_evidence_count' => 4,
            ],
            'terminal_loop_cycle_supervisor' => [
                'status' => 'idle',
                'cycle_state' => 'waiting',
                'next_command_purpose' => 'proof_check',
                'terminal_loop_cycle_supervisor_hash' => 'hash-abc',
            ],
            'terminal_loop_health_digest_hash' => 'digest-hash-xyz',
            'runtime_safety' => [
                'runtime_execution_allowed' => false,
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'self_programming_allowed' => false,
            ],
        ];

        $summary = TerminalLoopProofDigestInterpreter::digestSummary($digest);

        self::assertSame('running', $summary['status']);
        self::assertSame('ready', $summary['supply_status']);
        self::assertSame(5, $summary['claimable_task_count']);
        self::assertSame(2, $summary['claimed_task_count']);
        self::assertSame(2, $summary['active_lease_count']);
        self::assertSame(1, $summary['recoverable_lease_count']);
        self::assertTrue($summary['recoverable_backlog_present']);
        self::assertTrue($summary['evidence_ready_for_review']);
        self::assertSame(4, $summary['valid_completion_evidence_count']);
        self::assertSame('idle', $summary['cycle_supervisor_status']);
        self::assertSame('waiting', $summary['cycle_supervisor_cycle_state']);
        self::assertSame('proof_check', $summary['next_command_purpose']);
        self::assertSame('proof_check', $summary['cycle_supervisor_next_command_purpose']);
        self::assertSame('hash-abc', $summary['cycle_supervisor_hash']);
        self::assertSame('digest-hash-xyz', $summary['digest_hash']);
        self::assertSame([], $summary['safety_blockers']);
    }

    // ── runtimeSafetyAllFalse ──────────────────────────────────────────

    public function test_runtime_safety_all_false_when_all_zero(): void
    {
        $completion = [
            'completion_real_allowed' => false,
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
        ];
        $digest = [
            'runtime_safety' => [
                'runtime_execution_allowed' => false,
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'self_programming_allowed' => false,
            ],
        ];

        self::assertTrue(TerminalLoopProofDigestInterpreter::runtimeSafetyAllFalse($completion, $digest));
    }

    public function test_runtime_safety_false_when_any_true(): void
    {
        $completion = [
            'completion_real_allowed' => false,
            'runtime_execution_allowed' => true, // This makes it unsafe
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
        ];
        $digest = [
            'runtime_safety' => [
                'runtime_execution_allowed' => false,
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'self_programming_allowed' => false,
            ],
        ];

        self::assertFalse(TerminalLoopProofDigestInterpreter::runtimeSafetyAllFalse($completion, $digest));
    }

    public function test_runtime_safety_false_when_digest_flag_true(): void
    {
        $completion = [
            'completion_real_allowed' => false,
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
        ];
        $digest = [
            'runtime_safety' => [
                'runtime_execution_allowed' => false,
                'dispatch_allowed' => true, // This makes it unsafe
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'self_programming_allowed' => false,
            ],
        ];

        self::assertFalse(TerminalLoopProofDigestInterpreter::runtimeSafetyAllFalse($completion, $digest));
    }

    public function test_runtime_safety_false_when_empty(): void
    {
        $completion = [];
        $digest = [];

        // All completion flags default to true via data_get default, so runtime not safe
        self::assertFalse(TerminalLoopProofDigestInterpreter::runtimeSafetyAllFalse($completion, $digest));
    }

    public function test_runtime_safety_all_false_fail_closed_when_completion_flag_missing(): void
    {
        $completion = [
            'completion_real_allowed' => false,
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            // self_programming_allowed is missing — defaults to true, so unsafe
        ];
        $digest = [
            'runtime_safety' => [
                'runtime_execution_allowed' => false,
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'self_programming_allowed' => false,
            ],
        ];

        self::assertFalse(TerminalLoopProofDigestInterpreter::runtimeSafetyAllFalse($completion, $digest));
    }

    // ── digestSummary: supply_status ───────────────────────────────────

    public function test_digest_summary_supply_status_is_ready_when_claimable_above_zero(): void
    {
        $digest = ['queue_health' => ['claimable_task_count' => 1]];
        $summary = TerminalLoopProofDigestInterpreter::digestSummary($digest);

        self::assertSame('ready', $summary['supply_status']);
    }

    public function test_digest_summary_supply_status_is_depleted_when_claimable_is_zero(): void
    {
        $digest = ['queue_health' => ['claimable_task_count' => 0]];
        $summary = TerminalLoopProofDigestInterpreter::digestSummary($digest);

        self::assertSame('depleted', $summary['supply_status']);
    }

    // ── digestSummary: recoverable_backlog_present ──────────────────────

    public function test_digest_summary_recoverable_backlog_present_is_true_when_recoverable_above_zero(): void
    {
        $digest = ['lease_health' => ['recoverable_lease_count' => 3]];
        $summary = TerminalLoopProofDigestInterpreter::digestSummary($digest);

        self::assertTrue($summary['recoverable_backlog_present']);
    }

    public function test_digest_summary_recoverable_backlog_present_is_false_when_zero(): void
    {
        $digest = ['lease_health' => ['recoverable_lease_count' => 0]];
        $summary = TerminalLoopProofDigestInterpreter::digestSummary($digest);

        self::assertFalse($summary['recoverable_backlog_present']);
    }

    // ── digestSummary: evidence_ready_for_review ────────────────────────

    public function test_digest_summary_evidence_ready_for_review_is_true_when_valid_count_above_zero(): void
    {
        $digest = ['terminal_loop_fleet_evidence_rollup' => ['valid_completion_evidence_count' => 2]];
        $summary = TerminalLoopProofDigestInterpreter::digestSummary($digest);

        self::assertTrue($summary['evidence_ready_for_review']);
    }

    public function test_digest_summary_evidence_ready_for_review_is_false_when_zero(): void
    {
        $digest = ['terminal_loop_fleet_evidence_rollup' => ['valid_completion_evidence_count' => 0]];
        $summary = TerminalLoopProofDigestInterpreter::digestSummary($digest);

        self::assertFalse($summary['evidence_ready_for_review']);
    }

    // ── digestSummary: next_command_purpose ────────────────────────────

    public function test_digest_summary_next_command_purpose_mirrors_cycle_supervisor(): void
    {
        $digest = ['terminal_loop_cycle_supervisor' => ['next_command_purpose' => 'replenish_queue']];
        $summary = TerminalLoopProofDigestInterpreter::digestSummary($digest);

        self::assertSame('replenish_queue', $summary['next_command_purpose']);
        self::assertSame('replenish_queue', $summary['cycle_supervisor_next_command_purpose']);
    }

    // ── digestSummary: safety_blockers ─────────────────────────────────

    public function test_digest_summary_safety_blockers_lists_missing_flags_when_runtime_safety_absent(): void
    {
        $digest = []; // no runtime_safety section at all
        $summary = TerminalLoopProofDigestInterpreter::digestSummary($digest);

        $expected = [
            'missing:runtime_execution_allowed',
            'missing:dispatch_allowed',
            'missing:provider_call_allowed',
            'missing:token_spend_allowed',
            'missing:self_programming_allowed',
        ];
        foreach ($expected as $blocker) {
            self::assertContains($blocker, $summary['safety_blockers']);
        }
        self::assertCount(5, $summary['safety_blockers']);
    }

    public function test_digest_summary_safety_blockers_lists_unsafe_flag_when_true(): void
    {
        $digest = [
            'runtime_safety' => [
                'runtime_execution_allowed' => true,
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'self_programming_allowed' => false,
            ],
        ];
        $summary = TerminalLoopProofDigestInterpreter::digestSummary($digest);

        self::assertContains('unsafe:runtime_execution_allowed', $summary['safety_blockers']);
        self::assertCount(1, $summary['safety_blockers']);
    }

    public function test_digest_summary_safety_blockers_is_empty_when_all_flags_are_false(): void
    {
        $digest = [
            'runtime_safety' => [
                'runtime_execution_allowed' => false,
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'self_programming_allowed' => false,
            ],
        ];
        $summary = TerminalLoopProofDigestInterpreter::digestSummary($digest);

        self::assertSame([], $summary['safety_blockers']);
    }
}
