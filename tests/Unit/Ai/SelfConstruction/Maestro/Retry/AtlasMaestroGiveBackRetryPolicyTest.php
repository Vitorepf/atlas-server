<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Retry;

use App\Services\Ai\SelfConstruction\Maestro\Retry\AtlasMaestroGiveBackRetryPolicy;
use App\Services\Ai\SelfConstruction\Maestro\Retry\RetryDecision;
use Tests\TestCase;

final class AtlasMaestroGiveBackRetryPolicyTest extends TestCase
{
    public function test_allows_first_retry_when_budget_remains_and_no_loop(): void
    {
        $policy = new AtlasMaestroGiveBackRetryPolicy();
        $verdict = $policy->evaluate([
            'task_packet_id' => 'pkt-1',
            'retries_used' => 0,
            'new_fingerprint' => 'fp-new',
            'previous_fingerprint' => 'fp-old',
            'cooldown_until_unix' => 0,
            'now_unix' => 1700000000,
        ]);
        $this->assertTrue($verdict->allow);
        $this->assertSame(1, $verdict->nextAttemptIndex);
    }

    public function test_budget_exhausted_denies_retry(): void
    {
        $policy = new AtlasMaestroGiveBackRetryPolicy(maxRetries: 2);
        $verdict = $policy->evaluate([
            'task_packet_id' => 'pkt-1',
            'retries_used' => 2,
            'new_fingerprint' => 'fp-new',
            'previous_fingerprint' => 'fp-old',
        ]);
        $this->assertFalse($verdict->allow);
        $this->assertSame(RetryDecision::REASON_BUDGET_EXHAUSTED, $verdict->reason);
    }

    public function test_identical_reshape_fingerprint_denies_with_loop_detected(): void
    {
        $policy = new AtlasMaestroGiveBackRetryPolicy();
        $verdict = $policy->evaluate([
            'task_packet_id' => 'pkt-1',
            'retries_used' => 0,
            'new_fingerprint' => 'fp-same',
            'previous_fingerprint' => 'fp-same',
            'cooldown_until_unix' => 0,
            'now_unix' => 1700000000,
        ]);
        $this->assertFalse($verdict->allow);
        $this->assertSame(RetryDecision::REASON_LOOP_DETECTED, $verdict->reason);
    }

    public function test_cooldown_active_denies_retry(): void
    {
        $policy = new AtlasMaestroGiveBackRetryPolicy();
        $verdict = $policy->evaluate([
            'task_packet_id' => 'pkt-1',
            'retries_used' => 0,
            'new_fingerprint' => 'fp-new',
            'previous_fingerprint' => 'fp-old',
            'cooldown_until_unix' => 1700000060,
            'now_unix' => 1700000000,
        ]);
        $this->assertFalse($verdict->allow);
        $this->assertSame(RetryDecision::REASON_COOLDOWN_ACTIVE, $verdict->reason);
    }

    public function test_loop_detected_takes_precedence_over_budget_and_cooldown(): void
    {
        $policy = new AtlasMaestroGiveBackRetryPolicy(maxRetries: 0);
        $verdict = $policy->evaluate([
            'task_packet_id' => 'pkt-1',
            'retries_used' => 99,
            'new_fingerprint' => 'fp-dup',
            'previous_fingerprint' => 'fp-dup',
            'cooldown_until_unix' => 9999999999,
            'now_unix' => 1700000000,
        ]);
        $this->assertSame(RetryDecision::REASON_LOOP_DETECTED, $verdict->reason);
    }

    public function test_give_back_count_at_threshold_returns_respec_or_retire(): void
    {
        $policy = new AtlasMaestroGiveBackRetryPolicy(maxRetries: 10, repeatedGiveBackThreshold: 3);
        $verdict = $policy->evaluate([
            'task_packet_id' => 'pkt-repeat',
            'retries_used' => 0,
            'new_fingerprint' => 'fp-new',
            'previous_fingerprint' => 'fp-old',
            'give_back_count' => 3,
        ]);
        $this->assertFalse($verdict->allow);
        $this->assertSame(AtlasMaestroGiveBackRetryPolicy::REASON_RESPEC_OR_RETIRE, $verdict->reason);
    }

    public function test_give_back_count_below_threshold_allows_retry(): void
    {
        $policy = new AtlasMaestroGiveBackRetryPolicy(maxRetries: 10, repeatedGiveBackThreshold: 3);
        $verdict = $policy->evaluate([
            'task_packet_id' => 'pkt-repeat',
            'retries_used' => 0,
            'new_fingerprint' => 'fp-new',
            'previous_fingerprint' => 'fp-old',
            'give_back_count' => 2,
        ]);
        $this->assertTrue($verdict->allow, 'give_back_count below threshold must still allow retry');
    }

    public function test_respec_or_retire_takes_precedence_over_loop_and_budget_and_cooldown(): void
    {
        $policy = new AtlasMaestroGiveBackRetryPolicy(maxRetries: 0, repeatedGiveBackThreshold: 1);
        $verdict = $policy->evaluate([
            'task_packet_id' => 'pkt-repeat',
            'retries_used' => 99,
            'new_fingerprint' => 'fp-dup',
            'previous_fingerprint' => 'fp-dup',
            'give_back_count' => 1,
            'cooldown_until_unix' => 9999999999,
            'now_unix' => 1700000000,
        ]);
        $this->assertSame(AtlasMaestroGiveBackRetryPolicy::REASON_RESPEC_OR_RETIRE, $verdict->reason,
            'respec_or_retire must win over loop_detected, budget_exhausted, and cooldown_active');
    }
}
