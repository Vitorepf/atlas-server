<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentRuntimeRegistryLoadBalancingPolicy;
use Tests\TestCase;

final class AgentRuntimeRegistryLoadBalancingPolicyTest extends TestCase
{
    private AgentRuntimeRegistryLoadBalancingPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new AgentRuntimeRegistryLoadBalancingPolicy;
    }

    private function candidate(array $overrides = []): array
    {
        return array_replace([
            'agent_id' => 'agent-1',
            'kind' => 'real_agent',
            'capability_score' => 0.8,
            'load_score' => 0.5,
            'risk_score' => 0.3,
            'capabilities' => ['phpunit', 'php'],
            'max_parallel_tasks' => 3,
            'current_task_count' => 1,
        ], $overrides);
    }

    // ── AC: rank() orders by capability, load, risk ─────────────────────────

    public function test_rank_returns_required_output_keys(): void
    {
        $result = $this->policy->rank([$this->candidate()]);

        foreach (['schema_version', 'mode', 'policy', 'ranked_agents', 'selected_agent', 'ranking_reasons', 'ranking_hash'] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
    }

    public function test_rank_capability_score_orders_high_first(): void
    {
        $result = $this->policy->rank([
            $this->candidate(['agent_id' => 'low', 'capability_score' => 0.3]),
            $this->candidate(['agent_id' => 'high', 'capability_score' => 0.9]),
        ], ['policy' => 'capability_score']);

        $this->assertSame('high', $result['ranked_agents'][0]['agent_id']);
        $this->assertSame('low', $result['ranked_agents'][1]['agent_id']);
    }

    public function test_rank_least_loaded_orders_free_slots_desc(): void
    {
        $result = $this->policy->rank([
            $this->candidate(['agent_id' => 'busy', 'max_parallel_tasks' => 3, 'current_task_count' => 2]),
            $this->candidate(['agent_id' => 'free', 'max_parallel_tasks' => 3, 'current_task_count' => 0]),
        ], ['policy' => 'least_loaded']);

        $this->assertSame('free', $result['ranked_agents'][0]['agent_id']);
    }

    public function test_rank_risk_first_human_prefers_human_approved(): void
    {
        $result = $this->policy->rank([
            $this->candidate(['agent_id' => 'auto', 'capabilities' => ['php'], 'risk_score' => 0.9]),
            $this->candidate(['agent_id' => 'human', 'capabilities' => ['php', 'human_approval'], 'risk_score' => 0.5]),
        ], ['policy' => 'risk_first_human']);

        $this->assertSame('human', $result['ranked_agents'][0]['agent_id']);
    }

    public function test_rank_dry_run_preferred_prefers_dry_run_agents(): void
    {
        $result = $this->policy->rank([
            $this->candidate(['agent_id' => 'real', 'kind' => 'real_agent']),
            $this->candidate(['agent_id' => 'dry', 'kind' => 'dry_run_agent']),
        ], ['policy' => 'dry_run_preferred']);

        $this->assertSame('dry', $result['ranked_agents'][0]['agent_id']);
    }

    public function test_rank_stable_order_sorts_by_agent_id(): void
    {
        $result = $this->policy->rank([
            $this->candidate(['agent_id' => 'charlie']),
            $this->candidate(['agent_id' => 'alpha']),
            $this->candidate(['agent_id' => 'bravo']),
        ], ['policy' => 'stable_order']);

        $this->assertSame('alpha', $result['ranked_agents'][0]['agent_id']);
        $this->assertSame('bravo', $result['ranked_agents'][1]['agent_id']);
        $this->assertSame('charlie', $result['ranked_agents'][2]['agent_id']);
    }

    public function test_rank_unknown_policy_defaults_to_capability_score(): void
    {
        $result = $this->policy->rank([$this->candidate()], ['policy' => 'nonexistent']);

        $this->assertSame('capability_score', $result['policy']);
    }

    public function test_rank_selected_agent_is_first(): void
    {
        $result = $this->policy->rank([
            $this->candidate(['agent_id' => 'a', 'capability_score' => 0.3]),
            $this->candidate(['agent_id' => 'b', 'capability_score' => 0.9]),
        ]);

        $this->assertSame('b', $result['selected_agent']);
    }

    public function test_rank_empty_candidates_returns_null_selected(): void
    {
        $result = $this->policy->rank([]);

        $this->assertNull($result['selected_agent']);
        $this->assertSame([], $result['ranked_agents']);
    }

    // ── AC: assignForTask() returns no_assignment when gates fail ───────────

    public function test_assign_for_task_assigns_qualified_candidate(): void
    {
        $result = $this->policy->assignForTask(
            ['difficulty' => 0.5, 'required_capabilities' => ['php']],
            [$this->candidate(['agent_id' => 'qualified', 'capability_score' => 0.8])],
        );

        $this->assertSame('assign', $result['decision']);
        $this->assertSame('qualified', $result['selected_agent']);
    }

    public function test_assign_for_task_returns_no_assignment_when_capability_too_low(): void
    {
        $result = $this->policy->assignForTask(
            ['difficulty' => 0.9],
            [$this->candidate(['agent_id' => 'weak', 'capability_score' => 0.3])],
        );

        $this->assertSame('wait_or_route_elsewhere', $result['decision']);
        $this->assertNull($result['selected_agent']);
    }

    public function test_assign_for_task_returns_no_assignment_when_no_free_slots(): void
    {
        $result = $this->policy->assignForTask(
            ['difficulty' => 0.3],
            [$this->candidate(['agent_id' => 'full', 'max_parallel_tasks' => 2, 'current_task_count' => 2])],
        );

        $this->assertSame('wait_or_route_elsewhere', $result['decision']);
    }

    public function test_assign_for_task_returns_no_assignment_when_failure_rate_too_high(): void
    {
        $result = $this->policy->assignForTask(
            ['difficulty' => 0.3],
            [$this->candidate(['agent_id' => 'flaky', 'recent_failure_rate' => 0.5])],
        );

        $this->assertSame('wait_or_route_elsewhere', $result['decision']);
    }

    public function test_assign_for_task_returns_no_assignment_when_capability_missing(): void
    {
        $result = $this->policy->assignForTask(
            ['difficulty' => 0.3, 'required_capabilities' => ['python']],
            [$this->candidate(['agent_id' => 'php-only', 'capabilities' => ['php']])],
        );

        $this->assertSame('wait_or_route_elsewhere', $result['decision']);
    }

    public function test_assign_for_task_prefers_higher_capability(): void
    {
        $result = $this->policy->assignForTask(
            ['difficulty' => 0.3],
            [
                $this->candidate(['agent_id' => 'ok', 'capability_score' => 0.5]),
                $this->candidate(['agent_id' => 'great', 'capability_score' => 0.95]),
            ],
        );

        $this->assertSame('great', $result['selected_agent']);
    }

    public function test_assign_for_task_quality_reason_includes_difficulty(): void
    {
        $result = $this->policy->assignForTask(
            ['difficulty' => 0.7],
            [$this->candidate(['agent_id' => 'strong', 'capability_score' => 0.9])],
        );

        $this->assertStringContainsString('difficulty=0.70', $result['quality_reason']);
    }

    // ── AC: routeToClass() balances supply pressure ─────────────────────────

    public function test_route_to_class_returns_no_claimable_when_empty(): void
    {
        $result = $this->policy->routeToClass([]);

        $this->assertNull($result['selected_class']);
        $this->assertSame('no_claimable_class_available', $result['reason']);
    }

    public function test_route_to_class_returns_no_claimable_when_all_zero(): void
    {
        $result = $this->policy->routeToClass([
            ['class_id' => 'A', 'claimable_now' => 0],
            ['class_id' => 'B', 'claimable_now' => 0],
        ]);

        $this->assertNull($result['selected_class']);
    }

    public function test_route_to_class_highest_claimable_without_pressure(): void
    {
        $result = $this->policy->routeToClass([
            ['class_id' => 'A', 'claimable_now' => 5],
            ['class_id' => 'B', 'claimable_now' => 20],
        ]);

        $this->assertSame('B', $result['selected_class']);
        $this->assertSame('route_to_highest_claimable_class', $result['reason']);
        $this->assertFalse($result['supply_pressure']);
    }

    public function test_route_to_class_balances_under_supply_pressure(): void
    {
        $result = $this->policy->routeToClass(
            [
                ['class_id' => 'scarce', 'claimable_now' => 2],
                ['class_id' => 'plenty', 'claimable_now' => 50],
            ],
            ['servable_now' => 5, 'active_leases' => 10], // 5 < 10*10=100 → pressure
        );

        // Under pressure, route to LEAST-drained (scarce first to prevent exhaustion)
        $this->assertTrue($result['supply_pressure']);
        $this->assertSame('supply_pressure_balance_claimable_classes', $result['reason']);
    }

    public function test_route_to_class_skips_zero_claimable(): void
    {
        $result = $this->policy->routeToClass([
            ['class_id' => 'empty', 'claimable_now' => 0],
            ['class_id' => 'available', 'claimable_now' => 10],
        ]);

        $this->assertSame('available', $result['selected_class']);
    }

    // ── runtime safety ──────────────────────────────────────────────────────

    public function test_runtime_flags_all_false(): void
    {
        $flags = $this->policy->runtimeFlags();

        foreach ($flags as $flag => $value) {
            $this->assertFalse($value, "{$flag} must be false");
        }
    }

    public function test_rank_never_allows_execution(): void
    {
        $result = $this->policy->rank([$this->candidate()]);

        $this->assertFalse($result['runtime_execution_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
    }

    // ── determinism ─────────────────────────────────────────────────────────

    public function test_rank_hash_is_hex64(): void
    {
        $result = $this->policy->rank([$this->candidate()]);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['ranking_hash']);
    }

    public function test_rank_deterministic_for_same_input(): void
    {
        $candidates = [$this->candidate(), $this->candidate(['agent_id' => 'agent-2'])];

        $a = $this->policy->rank($candidates);
        $b = $this->policy->rank($candidates);

        $this->assertSame($a['ranking_hash'], $b['ranking_hash']);
    }
}
