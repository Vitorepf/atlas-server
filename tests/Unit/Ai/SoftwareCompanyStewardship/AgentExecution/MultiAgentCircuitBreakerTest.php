<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AgentExecution;

use App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\MultiAgentCircuitBreaker;
use PHPUnit\Framework\TestCase;

final class MultiAgentCircuitBreakerTest extends TestCase
{
    public function test_three_identical_failures_force_replan(): void
    {
        $decision = (new MultiAgentCircuitBreaker)->evaluate(['failure_fingerprint' => 'fp-1', 'same_failure_count' => 3]);
        self::assertSame('replan', $decision['status']);
        self::assertTrue($decision['triggered']);
    }

    public function test_two_rounds_without_evidence_delta_change_approach(): void
    {
        $decision = (new MultiAgentCircuitBreaker)->evaluate(['evidence_delta_rounds' => 2]);
        self::assertSame('change_approach', $decision['status']);
    }

    public function test_provider_outage_pauses_durably_and_hard_stop_has_precedence(): void
    {
        $pause = (new MultiAgentCircuitBreaker)->evaluate(['execution_requested' => true, 'provider_available' => false]);
        self::assertSame('durable_pause', $pause['status']);

        $stop = (new MultiAgentCircuitBreaker)->evaluate([
            'execution_requested' => true, 'provider_available' => false, 'ledger_consistent' => false,
        ]);
        self::assertSame('hard_stop', $stop['status']);
    }

    public function test_candidate_without_frontier_improvement_ends_only_that_line(): void
    {
        $decision = (new MultiAgentCircuitBreaker)->evaluate(['candidate_id' => 'candidate-a', 'frontier_improved' => false]);
        self::assertSame('end_candidate_line', $decision['status']);
        self::assertSame('end_candidate_without_ending_mission', $decision['next_action']);
    }

    public function test_clean_facts_continue_and_hash_is_deterministic(): void
    {
        $breaker = new MultiAgentCircuitBreaker;
        $a = $breaker->evaluate([]);
        $b = $breaker->evaluate([]);
        self::assertSame('clear', $a['status']);
        self::assertSame($a['decision_hash'], $b['decision_hash']);
    }

    public function test_thresholds_are_closed_below_boundary_and_open_at_boundary(): void
    {
        $breaker = new MultiAgentCircuitBreaker;

        self::assertSame('clear', $breaker->evaluate([
            'failure_fingerprint' => 'fp-1', 'same_failure_count' => 2,
        ])['status']);
        self::assertSame('replan', $breaker->evaluate([
            'failure_fingerprint' => 'fp-1', 'same_failure_count' => 3,
        ])['status']);
        self::assertSame('clear', $breaker->evaluate(['evidence_delta_rounds' => 1])['status']);
        self::assertSame('change_approach', $breaker->evaluate(['evidence_delta_rounds' => 2])['status']);
    }

    public function test_invalid_authority_and_irreversibility_are_hard_stops(): void
    {
        $breaker = new MultiAgentCircuitBreaker;

        foreach ([
            ['authority_valid' => false, 'reason' => 'authority_invalid'],
            ['ledger_consistent' => false, 'reason' => 'ledger_inconsistent'],
            ['irreversible_requested' => true, 'reason' => 'irreversibility_outside_envelope'],
        ] as $facts) {
            $decision = $breaker->evaluate($facts);
            self::assertSame('hard_stop', $decision['status']);
            self::assertSame($facts['reason'], $decision['reason']);
        }
    }

    public function test_restart_replays_each_trigger_and_repeated_evidence_keeps_the_same_receipt(): void
    {
        $factsByBoundary = [
            ['failure_fingerprint' => 'fp-1', 'same_failure_count' => 3],
            ['evidence_delta_rounds' => 2],
            ['execution_requested' => true, 'provider_available' => false],
            ['irreversible_requested' => true],
            ['candidate_id' => 'candidate-a', 'frontier_improved' => false],
        ];
        $breaker = new MultiAgentCircuitBreaker;

        foreach ($factsByBoundary as $facts) {
            $first = $breaker->evaluate($facts);
            $restarted = (new MultiAgentCircuitBreaker)->evaluate($facts);
            self::assertTrue($first['triggered']);
            self::assertSame($first['status'], $restarted['status']);
            self::assertSame($first['decision_hash'], $restarted['decision_hash']);
        }

        $repeated = ['failure_fingerprint' => 'same-evidence', 'same_failure_count' => 3];
        $first = $breaker->evaluate($repeated);
        $second = $breaker->evaluate($repeated);
        self::assertSame($first['decision_hash'], $second['decision_hash']);
        self::assertSame(3, $second['same_failure_count']);
    }
}
