<?php

declare(strict_types=1);

namespace Tests\Unit\AgentGovernance;

use App\Services\Ai\AgentGovernance\AgentDesiredState;
use Tests\TestCase;

/**
 * The FREIO logic (pure): an agent is authorized ONLY when ON and not braked by TTL or budget.
 */
final class AgentDesiredStateTest extends TestCase
{
    public function test_off_is_never_effectively_on(): void
    {
        $s = new AgentDesiredState(agentKey: 'loop', on: false);
        $this->assertFalse($s->effectivelyOn(1_000, 0.0));
    }

    public function test_on_with_no_freio_is_effectively_on(): void
    {
        $s = new AgentDesiredState(agentKey: 'loop', on: true);
        $this->assertTrue($s->effectivelyOn(1_000_000, 999.0));
        $this->assertFalse($s->isExpired(1_000_000, 999.0));
        $this->assertNull($s->expiryReason(1_000_000, 999.0));
    }

    public function test_ttl_brake_trips_at_deadline(): void
    {
        $s = new AgentDesiredState(agentKey: 'loop', on: true, ttlExpiresAtEpoch: 1_000);
        $this->assertTrue($s->effectivelyOn(999, 0.0), 'before deadline: ON');
        $this->assertFalse($s->effectivelyOn(1_000, 0.0), 'at deadline: auto-OFF');
        $this->assertFalse($s->effectivelyOn(1_001, 0.0), 'past deadline: auto-OFF');
        $this->assertSame('ttl_expired', $s->expiryReason(1_000));
        $this->assertSame(1, $s->ttlRemainingSeconds(999));
        $this->assertSame(0, $s->ttlRemainingSeconds(1_000));
    }

    public function test_budget_brake_trips_at_ceiling(): void
    {
        $s = new AgentDesiredState(agentKey: 'loop', on: true, budgetLimitUsd: 5.0);
        $this->assertTrue($s->effectivelyOn(1_000, 4.99), 'under budget: ON');
        $this->assertFalse($s->effectivelyOn(1_000, 5.0), 'at budget: auto-OFF');
        $this->assertFalse($s->effectivelyOn(1_000, 6.0), 'over budget: auto-OFF');
        $this->assertSame('budget_exhausted', $s->expiryReason(1_000, 5.0));
    }

    public function test_no_ttl_means_no_time_remaining_number(): void
    {
        $s = new AgentDesiredState(agentKey: 'loop', on: true);
        $this->assertNull($s->ttlRemainingSeconds(1_000));
    }
}
