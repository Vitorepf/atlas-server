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
}