<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentRuntimeRegistryAvailabilityPlanner;
use PHPUnit\Framework\TestCase;

final class AgentRuntimeRegistryAvailabilityPlannerTest extends TestCase
{
    private function planner(): AgentRuntimeRegistryAvailabilityPlanner
    {
        return new AgentRuntimeRegistryAvailabilityPlanner;
    }

    private function agent(array $overrides = []): array
    {
        return array_merge([
            'agent_id' => 'agent-1',
            'kind' => 'muscle',
            'status' => 'available',
            'capabilities' => ['implementation'],
            'max_parallel_tasks' => 2,
            'current_task_count' => 0,
            'heartbeat_required' => false,
        ], $overrides);
    }

    // ── AC1: availability_status classification ────────────────────────────────

    public function test_healthy_agent_is_classified_available(): void
    {
        $plan = $this->planner()->plan([$this->agent()]);

        $this->assertSame('available', $plan['available_agents'][0]['availability_status']);
    }

    public function test_quarantined_status_agent_is_classified_quarantined(): void
    {
        $plan = $this->planner()->plan([$this->agent(['status' => 'quarantined'])]);

        $this->assertSame('quarantined', $plan['unavailable_agents'][0]['availability_status']);
    }

    public function test_quarantined_option_agent_is_classified_quarantined(): void
    {
        $plan = $this->planner()->plan([$this->agent(['agent_id' => 'agent-q'])], [], [
            'quarantined_agents' => ['agent-q'],
        ]);

        $this->assertSame('quarantined', $plan['unavailable_agents'][0]['availability_status']);
    }

    public function test_missing_required_capability_is_classified_capability_mismatch(): void
    {
        $plan = $this->planner()->plan([$this->agent(['capabilities' => ['research']])], [], [
            'required_capabilities' => ['implementation'],
        ]);

        $this->assertSame('capability_mismatch', $plan['unavailable_agents'][0]['availability_status']);
    }

    public function test_full_capacity_agent_is_classified_overloaded(): void
    {
        $plan = $this->planner()->plan([$this->agent(['max_parallel_tasks' => 2, 'current_task_count' => 2])]);

        $this->assertSame('overloaded', $plan['unavailable_agents'][0]['availability_status']);
    }

    public function test_stale_heartbeat_agent_is_classified_stale(): void
    {
        $plan = $this->planner()->plan(
            [$this->agent(['heartbeat_required' => true])],
            [['agent_id' => 'agent-1', 'observed_at' => '2020-01-01T00:00:00+00:00']],
            ['reference_time' => '2020-01-01T00:05:00+00:00', 'ttl_seconds' => 90],
        );

        $this->assertSame('stale', $plan['unavailable_agents'][0]['availability_status']);
    }

    public function test_quarantine_wins_over_stale_and_capability_mismatch(): void
    {
        $plan = $this->planner()->plan(
            [$this->agent(['status' => 'quarantined', 'capabilities' => [], 'heartbeat_required' => true])],
            [],
            ['required_capabilities' => ['implementation']],
        );

        $this->assertSame('quarantined', $plan['unavailable_agents'][0]['availability_status']);
    }

    // ── AC2: ranking by heartbeat freshness, capability fit, load, outcome quality ──

    public function test_available_agents_ranked_by_heartbeat_freshness(): void
    {
        $plan = $this->planner()->plan(
            [
                $this->agent(['agent_id' => 'stale-ish', 'heartbeat_required' => true]),
                $this->agent(['agent_id' => 'freshest', 'heartbeat_required' => true]),
            ],
            [
                ['agent_id' => 'stale-ish', 'observed_at' => '2020-01-01T00:00:30+00:00'],
                ['agent_id' => 'freshest', 'observed_at' => '2020-01-01T00:00:59+00:00'],
            ],
            ['reference_time' => '2020-01-01T00:01:00+00:00', 'ttl_seconds' => 90],
        );

        $this->assertSame('freshest', $plan['available_agents'][0]['agent_id']);
        $this->assertSame('stale-ish', $plan['available_agents'][1]['agent_id']);
    }

    public function test_available_agents_ranked_by_capability_fit_when_required_capabilities_set(): void
    {
        $plan = $this->planner()->plan(
            [
                $this->agent(['agent_id' => 'broad', 'capabilities' => ['implementation', 'research', 'review']]),
                $this->agent(['agent_id' => 'tight', 'capabilities' => ['implementation']]),
            ],
            [],
            ['required_capabilities' => ['implementation']],
        );

        // Both fully satisfy the requirement (fit_count=1 each since only 'implementation' is
        // required); with identical fit, load and quality, the tie-break is agent_id, so this
        // asserts fit is computed against the required set, not raw capability count.
        $this->assertSame(1, $plan['available_agents'][0]['capability_fit_count']);
        $this->assertSame(1, $plan['available_agents'][1]['capability_fit_count']);
    }

    public function test_available_agents_ranked_by_fewer_total_capabilities_when_none_required(): void
    {
        $plan = $this->planner()->plan([
            $this->agent(['agent_id' => 'specialist', 'capabilities' => ['implementation']]),
            $this->agent(['agent_id' => 'generalist', 'capabilities' => ['implementation', 'research', 'review']]),
        ]);

        $this->assertSame('specialist', $plan['available_agents'][0]['agent_id']);
        $this->assertSame('generalist', $plan['available_agents'][1]['agent_id']);
    }

    public function test_available_agents_ranked_by_lower_load_ratio(): void
    {
        $plan = $this->planner()->plan([
            $this->agent(['agent_id' => 'busy', 'max_parallel_tasks' => 4, 'current_task_count' => 3]),
            $this->agent(['agent_id' => 'idle', 'max_parallel_tasks' => 4, 'current_task_count' => 0]),
        ]);

        $this->assertSame('idle', $plan['available_agents'][0]['agent_id']);
        $this->assertSame('busy', $plan['available_agents'][1]['agent_id']);
    }

    public function test_available_agents_ranked_by_recent_outcome_quality_as_final_tiebreak(): void
    {
        $plan = $this->planner()->plan([
            $this->agent(['agent_id' => 'weaker', 'recent_outcome_quality' => 0.2]),
            $this->agent(['agent_id' => 'stronger', 'recent_outcome_quality' => 0.9]),
        ]);

        $this->assertSame('stronger', $plan['available_agents'][0]['agent_id']);
        $this->assertSame('weaker', $plan['available_agents'][1]['agent_id']);
    }

    public function test_recent_outcome_quality_defaults_to_half_when_absent(): void
    {
        $plan = $this->planner()->plan([$this->agent()]);

        $this->assertSame(0.5, $plan['available_agents'][0]['recent_outcome_quality']);
    }

    // ── AC3: no_eligible_agent + repair_reasons ─────────────────────────────────

    public function test_no_eligible_agent_true_with_repair_reasons_when_all_agents_blocked(): void
    {
        $plan = $this->planner()->plan([
            $this->agent(['agent_id' => 'a', 'status' => 'quarantined']),
            $this->agent(['agent_id' => 'b', 'max_parallel_tasks' => 1, 'current_task_count' => 1]),
        ]);

        $this->assertTrue($plan['no_eligible_agent']);
        $this->assertContains('quarantined_status', $plan['repair_reasons']);
        $this->assertContains('capacity_full', $plan['repair_reasons']);
    }

    public function test_no_eligible_agent_false_when_at_least_one_agent_available(): void
    {
        $plan = $this->planner()->plan([
            $this->agent(['agent_id' => 'a', 'status' => 'quarantined']),
            $this->agent(['agent_id' => 'b']),
        ]);

        $this->assertFalse($plan['no_eligible_agent']);
        $this->assertSame([], $plan['repair_reasons']);
    }

    public function test_no_eligible_agent_with_no_agents_registered_at_all(): void
    {
        $plan = $this->planner()->plan([]);

        $this->assertTrue($plan['no_eligible_agent']);
        $this->assertContains('no_agents_registered', $plan['repair_reasons']);
    }

    // ── Determinism ──────────────────────────────────────────────────────────────

    public function test_plan_is_deterministic(): void
    {
        $agents = [$this->agent(['agent_id' => 'a']), $this->agent(['agent_id' => 'b'])];
        $planner = $this->planner();

        $this->assertSame(
            $planner->plan($agents, [], ['reference_time' => '2020-01-01T00:00:00+00:00']),
            $planner->plan($agents, [], ['reference_time' => '2020-01-01T00:00:00+00:00']),
        );
    }
}
