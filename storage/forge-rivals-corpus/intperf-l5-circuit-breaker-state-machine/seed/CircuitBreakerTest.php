<?php

declare(strict_types=1);

namespace Tests\Unit\Resilience;

use App\Domain\Resilience\BackoffPolicy;
use App\Domain\Resilience\CircuitBreaker;
use PHPUnit\Framework\TestCase;

final class CircuitBreakerTest extends TestCase
{
    private function build(int $threshold = 3, int $base = 1, int $cap = 60): CircuitBreaker
    {
        // Post-patch: arm wires (BackoffPolicy, threshold, ClockInterface).
        return new CircuitBreaker(threshold: $threshold, backoff: new BackoffPolicy($base, $cap));
    }

    public function test_starts_closed(): void
    {
        $cb = $this->build();
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $cb->state());
    }

    public function test_transitions_to_open_after_threshold_failures(): void
    {
        $cb = $this->build(threshold: 2);
        $cb->execute(static fn () => throw new \RuntimeException('boom'));
        $cb->execute(static fn () => throw new \RuntimeException('boom'));
        $this->assertSame(CircuitBreaker::STATE_OPEN, $cb->state());
    }

    public function test_short_circuits_while_open(): void
    {
        $cb = $this->build(threshold: 1);
        $cb->execute(static fn () => throw new \RuntimeException('boom'));
        $result = $cb->execute(static fn () => 'never_runs');
        $this->assertSame('blocked', $result['status']);
        $this->assertSame('circuit_open', $result['reason']);
    }

    public function test_transitions_to_half_open_after_backoff(): void
    {
        $cb = $this->build(threshold: 1, base: 1);
        $cb->execute(static fn () => throw new \RuntimeException('boom'));
        // simulate backoff elapsed via the arm-provided clock seam:
        $cb->advanceClock(2);
        $result = $cb->execute(static fn () => 'recovered');
        $this->assertSame('ok', $result['status']);
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $cb->state(), 'success in half_open closes the breaker');
    }

    public function test_half_open_failure_reopens(): void
    {
        $cb = $this->build(threshold: 1, base: 1);
        $cb->execute(static fn () => throw new \RuntimeException('boom'));
        $cb->advanceClock(2);
        $cb->execute(static fn () => throw new \RuntimeException('boom-again'));
        $this->assertSame(CircuitBreaker::STATE_OPEN, $cb->state());
    }

    public function test_events_record_every_transition(): void
    {
        $cb = $this->build(threshold: 1, base: 1);
        $cb->execute(static fn () => throw new \RuntimeException('boom'));
        $cb->advanceClock(2);
        $cb->execute(static fn () => 'ok');
        $events = $cb->events();
        $this->assertGreaterThanOrEqual(2, count($events));
    }
}
