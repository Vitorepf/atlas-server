<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopOrchestrator;
use Tests\TestCase;

/**
 * MODEL-ROLE SEQUENCER + PARK-ON-OUTAGE + COST — frozen proof of the loop's deterministic control logic.
 *
 * The headline invariant: the loop NEVER ships work that was never critiqued. If the critic (the judge)
 * is down, the delivery PARKS — fail-closed on quality. If the designer is down, there is nothing to
 * critique/implement, so it parks too. Only designer+critic up lets the loop proceed, and then the role
 * sequence is the fixed ordered [designer, critic, implementer]. Cost charging throttles at 80% of the
 * ceiling and hard-stops at the ceiling so a runaway projection loop can't silently burn the budget.
 *
 * Assertions are EXACT (precise sequences, exact booleans/floats, parked sequence == []) — non-vacuous.
 */
final class AtlasLoopOrchestratorTest extends TestCase
{
    private function orchestrator(): AtlasLoopOrchestrator
    {
        return new AtlasLoopOrchestrator();
    }

    public function test_all_roles_up_proceeds_with_exact_ordered_sequence(): void
    {
        $plan = $this->orchestrator()->plan([
            'designer' => true,
            'critic' => true,
            'implementer' => true,
        ]);

        // Exact ordered sequence — not count>0.
        $this->assertSame(['designer', 'critic', 'implementer'], $plan['sequence']);
        $this->assertTrue($plan['can_proceed']);
        $this->assertFalse($plan['park']);
        $this->assertNull($plan['park_reason']);
    }

    public function test_critic_down_parks_with_reason_and_cannot_proceed(): void
    {
        $plan = $this->orchestrator()->plan([
            'designer' => true,
            'critic' => false,
            'implementer' => true,
        ]);

        $this->assertTrue($plan['park']);
        $this->assertFalse($plan['can_proceed']);
        $this->assertSame([], $plan['sequence']);
        $this->assertIsString($plan['park_reason']);
        $this->assertNotSame('', $plan['park_reason']);
        // The reason names the un-critiqued-work guardrail specifically.
        $this->assertStringContainsString('critic', $plan['park_reason']);
    }

    public function test_designer_down_parks_even_when_critic_up(): void
    {
        $plan = $this->orchestrator()->plan([
            'designer' => false,
            'critic' => true,
            'implementer' => true,
        ]);

        $this->assertTrue($plan['park']);
        $this->assertFalse($plan['can_proceed']);
        $this->assertSame([], $plan['sequence']);
        $this->assertIsString($plan['park_reason']);
        $this->assertStringContainsString('designer', $plan['park_reason']);
    }

    public function test_implementer_down_alone_does_not_park_the_projection(): void
    {
        // Designer + critic up is what lets the loop proceed; the implementer being down alone
        // must NOT block the projection (critic is the only ship-gate).
        $plan = $this->orchestrator()->plan([
            'designer' => true,
            'critic' => true,
            'implementer' => false,
        ]);

        $this->assertFalse($plan['park']);
        $this->assertTrue($plan['can_proceed']);
        $this->assertSame(['designer', 'critic', 'implementer'], $plan['sequence']);
        $this->assertNull($plan['park_reason']);
    }

    public function test_missing_critic_key_is_treated_as_down_and_parks(): void
    {
        // A role absent from the availability map is treated as DOWN (fail-closed), not assumed up.
        $plan = $this->orchestrator()->plan(['designer' => true]);

        $this->assertTrue($plan['park']);
        $this->assertFalse($plan['can_proceed']);
        $this->assertSame([], $plan['sequence']);
        $this->assertStringContainsString('critic', (string) $plan['park_reason']);
    }

    public function test_charge_cost_below_throttle_is_neither_throttled_nor_over(): void
    {
        // ceiling 100, throttle at 80. 30 + 40 = 70 < 80.
        $result = $this->orchestrator()->chargeCost(30.0, 40.0, 100.0);

        $this->assertSame(70.0, $result['accrued']);
        $this->assertFalse($result['throttled']);
        $this->assertFalse($result['over']);
    }

    public function test_charge_cost_crossing_80_percent_throttles_but_not_over(): void
    {
        // 50 + 35 = 85; 85 >= 80 (throttle) but 85 < 100 (not over).
        $result = $this->orchestrator()->chargeCost(50.0, 35.0, 100.0);

        $this->assertSame(85.0, $result['accrued']);
        $this->assertTrue($result['throttled']);
        $this->assertFalse($result['over']);
    }

    public function test_charge_cost_at_exact_throttle_boundary_throttles(): void
    {
        // accrued == 80 == 0.8*ceiling => throttled true (>=), over false.
        $result = $this->orchestrator()->chargeCost(60.0, 20.0, 100.0);

        $this->assertSame(80.0, $result['accrued']);
        $this->assertTrue($result['throttled']);
        $this->assertFalse($result['over']);
    }

    public function test_charge_cost_crossing_ceiling_is_over_and_throttled(): void
    {
        // 90 + 20 = 110 >= 100 => over true AND throttled true.
        $result = $this->orchestrator()->chargeCost(90.0, 20.0, 100.0);

        $this->assertSame(110.0, $result['accrued']);
        $this->assertTrue($result['throttled']);
        $this->assertTrue($result['over']);
    }

    public function test_charge_cost_at_exact_ceiling_boundary_is_over(): void
    {
        // accrued == ceiling => over true (>=).
        $result = $this->orchestrator()->chargeCost(70.0, 30.0, 100.0);

        $this->assertSame(100.0, $result['accrued']);
        $this->assertTrue($result['throttled']);
        $this->assertTrue($result['over']);
    }
}
