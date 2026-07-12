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
}
