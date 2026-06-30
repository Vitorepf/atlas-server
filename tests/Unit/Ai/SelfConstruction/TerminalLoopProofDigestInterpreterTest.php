<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\TerminalLoopProof\TerminalLoopProofDigestInterpreter;
use Tests\TestCase;

class TerminalLoopProofDigestInterpreterTest extends TestCase
{
    public function test_digest_summary_empty(): void
    {
        $summary = TerminalLoopProofDigestInterpreter::digestSummary([]);

        self::assertSame('', $summary['status']);
        self::assertSame(0, $summary['claimable_task_count']);
        self::assertSame('', $summary['digest_hash']);
    }

    public function test_digest_summary_full(): void
    {
        $digest = [
            'status' => 'available',
            'queue_health' => [
                'claimable_task_count' => 5,
                'claimed_task_count' => 2,
            ],
            'lease_health' => [
                'active_lease_count' => 1,
                'recoverable_lease_count' => 0,
            ],
            'terminal_loop_fleet_evidence_rollup' => [
                'status' => 'green',
                'completed_dry_run_task_count' => 10,
                'valid_completion_evidence_count' => 8,
            ],
            'terminal_loop_cycle_supervisor' => [
                'status' => 'ok',
                'cycle_state' => 'review_evidence',
                'next_command_purpose' => 'rerun',
                'terminal_loop_cycle_supervisor_hash' => 'cyc_hash',
            ],
            'terminal_loop_health_digest_hash' => 'main_hash',
        ];

        $summary = TerminalLoopProofDigestInterpreter::digestSummary($digest);

        self::assertSame('available', $summary['status']);
        self::assertSame(5, $summary['claimable_task_count']);
        self::assertSame(2, $summary['claimed_task_count']);
        self::assertSame(1, $summary['active_lease_count']);
        self::assertSame(0, $summary['recoverable_lease_count']);
        self::assertSame('green', $summary['evidence_rollup_status']);
        self::assertSame(10, $summary['completed_dry_run_task_count']);
        self::assertSame(8, $summary['valid_completion_evidence_count']);
        self::assertSame('ok', $summary['cycle_supervisor_status']);
        self::assertSame('review_evidence', $summary['cycle_supervisor_cycle_state']);
        self::assertSame('rerun', $summary['cycle_supervisor_next_command_purpose']);
        self::assertSame('cyc_hash', $summary['cycle_supervisor_hash']);
        self::assertSame('main_hash', $summary['digest_hash']);
    }

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
            'runtime_execution_allowed' => true,
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
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'self_programming_allowed' => true,
            ],
        ];

        self::assertFalse(TerminalLoopProofDigestInterpreter::runtimeSafetyAllFalse($completion, $digest));
    }

    public function test_runtime_safety_false_when_empty(): void
    {
        self::assertFalse(TerminalLoopProofDigestInterpreter::runtimeSafetyAllFalse([], []));
    }

    public function test_digest_summary_supply_status_is_ready_when_claimable_above_zero(): void
    {
        $summary = TerminalLoopProofDigestInterpreter::digestSummary([
            'queue_health' => ['claimable_task_count' => 3],
        ]);
        self::assertSame('ready', $summary['supply_status']);
    }

    public function test_digest_summary_supply_status_is_depleted_when_claimable_is_zero(): void
    {
        $summary = TerminalLoopProofDigestInterpreter::digestSummary([
            'queue_health' => ['claimable_task_count' => 0],
        ]);
        self::assertSame('depleted', $summary['supply_status']);
    }

    public function test_digest_summary_recoverable_backlog_present_is_true_when_recoverable_above_zero(): void
    {
        $summary = TerminalLoopProofDigestInterpreter::digestSummary([
            'lease_health' => ['recoverable_lease_count' => 2],
        ]);
        self::assertTrue($summary['recoverable_backlog_present']);
    }

    public function test_digest_summary_recoverable_backlog_present_is_false_when_zero(): void
    {
        $summary = TerminalLoopProofDigestInterpreter::digestSummary([]);
        self::assertFalse($summary['recoverable_backlog_present']);
    }

    public function test_digest_summary_evidence_ready_for_review_is_true_when_valid_count_above_zero(): void
    {
        $summary = TerminalLoopProofDigestInterpreter::digestSummary([
            'terminal_loop_fleet_evidence_rollup' => ['valid_completion_evidence_count' => 5],
        ]);
        self::assertTrue($summary['evidence_ready_for_review']);
    }

    public function test_digest_summary_evidence_ready_for_review_is_false_when_zero(): void
    {
        $summary = TerminalLoopProofDigestInterpreter::digestSummary([]);
        self::assertFalse($summary['evidence_ready_for_review']);
    }

    public function test_digest_summary_next_command_purpose_mirrors_cycle_supervisor(): void
    {
        $summary = TerminalLoopProofDigestInterpreter::digestSummary([
            'terminal_loop_cycle_supervisor' => ['next_command_purpose' => 'replenish_and_launch'],
        ]);
        self::assertSame('replenish_and_launch', $summary['next_command_purpose']);
        self::assertSame($summary['next_command_purpose'], $summary['cycle_supervisor_next_command_purpose']);
    }

    public function test_digest_summary_safety_blockers_lists_missing_flags_when_runtime_safety_absent(): void
    {
        $summary = TerminalLoopProofDigestInterpreter::digestSummary([]);
        self::assertNotEmpty($summary['safety_blockers']);
        $str = implode('|', $summary['safety_blockers']);
        self::assertStringContainsString('missing:', $str);
    }

    public function test_digest_summary_safety_blockers_lists_unsafe_flag_when_true(): void
    {
        $summary = TerminalLoopProofDigestInterpreter::digestSummary([
            'runtime_safety' => [
                'runtime_execution_allowed' => true,
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'self_programming_allowed' => false,
            ],
        ]);
        self::assertContains('unsafe:runtime_execution_allowed', $summary['safety_blockers']);
    }

    public function test_digest_summary_safety_blockers_is_empty_when_all_flags_are_false(): void
    {
        $summary = TerminalLoopProofDigestInterpreter::digestSummary([
            'runtime_safety' => [
                'runtime_execution_allowed' => false,
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'self_programming_allowed' => false,
            ],
        ]);
        self::assertSame([], $summary['safety_blockers']);
    }

    public function test_runtime_safety_all_false_fail_closed_when_completion_flag_missing(): void
    {
        // missing completion flags default to true (unsafe) so result must be false
        $allSafeDigest = [
            'runtime_safety' => [
                'runtime_execution_allowed' => false,
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'self_programming_allowed' => false,
            ],
        ];
        self::assertFalse(TerminalLoopProofDigestInterpreter::runtimeSafetyAllFalse([], $allSafeDigest));
    }
}