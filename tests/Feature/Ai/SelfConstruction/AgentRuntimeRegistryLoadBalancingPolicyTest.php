<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentRuntimeRegistryLoadBalancingPolicy;
use Tests\TestCase;

final class AgentRuntimeRegistryLoadBalancingPolicyTest extends TestCase
{
    public function test_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_runtime_registry_load_balancing_policy.v1', AgentRuntimeRegistryLoadBalancingPolicy::SCHEMA_VERSION);
        foreach (['least_loaded', 'capability_score', 'risk_first_human', 'dry_run_preferred', 'stable_order'] as $policy) {
            $this->assertContains($policy, AgentRuntimeRegistryLoadBalancingPolicy::POLICIES);
        }
    }

    public function test_least_loaded_ranking(): void
    {
        $svc = new AgentRuntimeRegistryLoadBalancingPolicy;
        $ranked = $svc->rank([
            $this->candidate('agent-a', ['max_parallel_tasks' => 4, 'current_task_count' => 3]),
            $this->candidate('agent-b', ['max_parallel_tasks' => 4, 'current_task_count' => 0]),
            $this->candidate('agent-c', ['max_parallel_tasks' => 4, 'current_task_count' => 1]),
        ], ['policy' => 'least_loaded']);
        $this->assertSame('agent-b', $ranked['selected_agent']);
        $this->assertSame('least_loaded', $ranked['policy']);
        $this->assertContains('least_loaded:free_slots', $ranked['ranking_reasons']);
        $this->assertFalse($ranked['dispatch_allowed']);
    }

    public function test_capability_score_ranking(): void
    {
        $svc = new AgentRuntimeRegistryLoadBalancingPolicy;
        $ranked = $svc->rank([
            $this->candidate('agent-low', ['capability_score' => 0.5]),
            $this->candidate('agent-high', ['capability_score' => 1.0]),
        ], ['policy' => 'capability_score']);
        $this->assertSame('agent-high', $ranked['selected_agent']);
    }

    public function test_risk_first_human_ranking(): void
    {
        $svc = new AgentRuntimeRegistryLoadBalancingPolicy;
        $ranked = $svc->rank([
            $this->candidate('agent-noop', ['capabilities' => ['code_edit']]),
            $this->candidate('agent-h', ['capabilities' => ['code_edit', 'human_approval'], 'risk_score' => 0.9]),
        ], ['policy' => 'risk_first_human']);
        $this->assertSame('agent-h', $ranked['selected_agent']);
    }

    public function test_dry_run_preferred_ranking(): void
    {
        $svc = new AgentRuntimeRegistryLoadBalancingPolicy;
        $ranked = $svc->rank([
            $this->candidate('agent-codex', ['kind' => 'codex']),
            $this->candidate('agent-dry', ['kind' => 'dry_run_agent']),
        ], ['policy' => 'dry_run_preferred']);
        $this->assertSame('agent-dry', $ranked['selected_agent']);
    }

    public function test_stable_order_deterministic(): void
    {
        $svc = new AgentRuntimeRegistryLoadBalancingPolicy;
        $ranked = $svc->rank([
            $this->candidate('zeta'),
            $this->candidate('alpha'),
            $this->candidate('mu'),
        ], ['policy' => 'stable_order']);
        $this->assertSame(['alpha', 'mu', 'zeta'], array_map(static fn (array $c): string => (string) $c['agent_id'], $ranked['ranked_agents']));
    }

    public function test_invalid_policy_falls_back_to_capability_score(): void
    {
        $svc = new AgentRuntimeRegistryLoadBalancingPolicy;
        $ranked = $svc->rank([$this->candidate('agent-a')], ['policy' => 'rogue']);
        $this->assertSame('capability_score', $ranked['policy']);
    }

    public function test_ranking_hash_stable(): void
    {
        $svc = new AgentRuntimeRegistryLoadBalancingPolicy;
        $a = $svc->rank([$this->candidate('agent-a'), $this->candidate('agent-b')], ['policy' => 'stable_order']);
        $b = $svc->rank([$this->candidate('agent-a'), $this->candidate('agent-b')], ['policy' => 'stable_order']);
        $this->assertSame($a['ranking_hash'], $b['ranking_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a['ranking_hash']);
    }

    public function test_runtime_flags_helper(): void
    {
        $svc = new AgentRuntimeRegistryLoadBalancingPolicy;
        foreach ($svc->runtimeFlags() as $key => $value) {
            $this->assertFalse($value, "flag {$key} must remain false");
        }
    }

    public function test_ignores_candidate_without_id(): void
    {
        $svc = new AgentRuntimeRegistryLoadBalancingPolicy;
        $ranked = $svc->rank([
            ['agent_id' => ''],
            $this->candidate('agent-a'),
        ], ['policy' => 'stable_order']);
        $this->assertSame('agent-a', $ranked['selected_agent']);
        $this->assertCount(1, $ranked['ranked_agents']);
    }

    public function test_runtime_flags_on_envelope(): void
    {
        $svc = new AgentRuntimeRegistryLoadBalancingPolicy;
        $ranked = $svc->rank([$this->candidate('agent-a')]);
        $this->assertFalse($ranked['dispatch_allowed']);
        $this->assertFalse($ranked['runtime_execution_allowed']);
        $this->assertFalse($ranked['ledger_write_allowed']);
        $this->assertFalse($ranked['provider_call_allowed']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function candidate(string $id, array $overrides = []): array
    {
        return array_merge([
            'agent_id' => $id,
            'kind' => 'codex',
            'capability_score' => 1.0,
            'load_score' => 1.0,
            'risk_score' => 0.5,
            'capabilities' => ['code_edit'],
            'max_parallel_tasks' => 2,
            'current_task_count' => 0,
        ], $overrides);
    }

    // ── assignForTask() ──────────────────────────────────────────────────────

    public function test_assigns_to_qualified_capable_idle_worker(): void
    {
        $svc = new AgentRuntimeRegistryLoadBalancingPolicy;
        $result = $svc->assignForTask(
            ['difficulty' => 0.7],
            [$this->candidate('agent-a', ['capability_score' => 0.9])],
        );

        $this->assertSame(AgentRuntimeRegistryLoadBalancingPolicy::DECISION_ASSIGN, $result['decision']);
        $this->assertSame('agent-a', $result['selected_agent']);
        $this->assertNotEmpty($result['quality_reason']);
        $this->assertNotEmpty($result['capacity_reason']);
    }

    public function test_does_not_assign_hard_task_to_weak_idle_worker(): void
    {
        $svc = new AgentRuntimeRegistryLoadBalancingPolicy;
        $result = $svc->assignForTask(
            ['difficulty' => 0.9],
            [$this->candidate('agent-weak', ['capability_score' => 0.3])],
        );

        $this->assertSame(AgentRuntimeRegistryLoadBalancingPolicy::DECISION_WAIT_OR_ROUTE_ELSEWHERE, $result['decision']);
        $this->assertNull($result['selected_agent']);
        $this->assertStringContainsString('quality bar', $result['quality_reason']);
    }

    public function test_does_not_assign_to_overloaded_worker_even_if_capable(): void
    {
        $svc = new AgentRuntimeRegistryLoadBalancingPolicy;
        $result = $svc->assignForTask(
            ['difficulty' => 0.5],
            [$this->candidate('agent-full', ['capability_score' => 1.0, 'max_parallel_tasks' => 2, 'current_task_count' => 2])],
        );

        $this->assertSame(AgentRuntimeRegistryLoadBalancingPolicy::DECISION_WAIT_OR_ROUTE_ELSEWHERE, $result['decision']);
        $this->assertStringContainsString('no candidates have free capacity', $result['capacity_reason']);
    }

    public function test_high_recent_failure_rate_disqualifies_worker(): void
    {
        $svc = new AgentRuntimeRegistryLoadBalancingPolicy;
        $candidate = $this->candidate('agent-flaky', ['capability_score' => 1.0]);
        $candidate['recent_failure_rate'] = 0.8;

        $result = $svc->assignForTask(['difficulty' => 0.5], [$candidate]);

        $this->assertSame(AgentRuntimeRegistryLoadBalancingPolicy::DECISION_WAIT_OR_ROUTE_ELSEWHERE, $result['decision']);
    }

    public function test_prefers_higher_capability_among_qualified_candidates(): void
    {
        $svc = new AgentRuntimeRegistryLoadBalancingPolicy;
        $result = $svc->assignForTask(
            ['difficulty' => 0.3],
            [
                $this->candidate('agent-mid', ['capability_score' => 0.6]),
                $this->candidate('agent-best', ['capability_score' => 0.95]),
            ],
        );

        $this->assertSame('agent-best', $result['selected_agent']);
    }

    public function test_family_mismatch_excludes_capable_idle_worker(): void
    {
        $svc = new AgentRuntimeRegistryLoadBalancingPolicy;
        $result = $svc->assignForTask(
            ['difficulty' => 0.3, 'required_capabilities' => ['security_audit']],
            [$this->candidate('agent-a', ['capabilities' => ['code_edit']])],
        );

        $this->assertSame(AgentRuntimeRegistryLoadBalancingPolicy::DECISION_WAIT_OR_ROUTE_ELSEWHERE, $result['decision']);
    }

    // --- routeToClass: claimable-supply-preserving class routing ---

    public function test_route_to_class_prefers_balanced_supply_under_pressure(): void
    {
        $svc = new AgentRuntimeRegistryLoadBalancingPolicy;
        $result = $svc->routeToClass(
            [
                ['class_id' => 'scarce', 'claimable_now' => 1],
                ['class_id' => 'plentiful', 'claimable_now' => 4],
            ],
            ['servable_now' => 5, 'active_leases' => 1], // 5 < 1*10 => supply pressure
        );

        $this->assertTrue($result['supply_pressure']);
        $this->assertSame('scarce', $result['selected_class']);
        $this->assertSame(AgentRuntimeRegistryLoadBalancingPolicy::CLASS_ROUTE_REASON_SUPPLY_PRESSURE_BALANCE, $result['reason']);
    }

    public function test_route_to_class_prefers_highest_claimable_without_pressure(): void
    {
        $svc = new AgentRuntimeRegistryLoadBalancingPolicy;
        $result = $svc->routeToClass(
            [
                ['class_id' => 'low', 'claimable_now' => 1],
                ['class_id' => 'high', 'claimable_now' => 40],
            ],
            ['servable_now' => 50, 'active_leases' => 1], // 50 >= 1*10 => no pressure
        );

        $this->assertFalse($result['supply_pressure']);
        $this->assertSame('high', $result['selected_class']);
        $this->assertSame(AgentRuntimeRegistryLoadBalancingPolicy::CLASS_ROUTE_REASON_HIGHEST_CLAIMABLE, $result['reason']);
    }

    public function test_route_to_class_never_selects_class_with_no_claimable_packets_while_others_exist(): void
    {
        $svc = new AgentRuntimeRegistryLoadBalancingPolicy;
        $result = $svc->routeToClass(
            [
                ['class_id' => 'empty', 'claimable_now' => 0],
                ['class_id' => 'useful', 'claimable_now' => 3],
            ],
            ['servable_now' => 3, 'active_leases' => 1],
        );

        $this->assertSame('useful', $result['selected_class']);
        $this->assertNotSame('empty', $result['selected_class']);
    }

    public function test_route_to_class_with_no_claimable_classes_at_all_returns_null(): void
    {
        $svc = new AgentRuntimeRegistryLoadBalancingPolicy;
        $result = $svc->routeToClass(
            [
                ['class_id' => 'empty-a', 'claimable_now' => 0],
                ['class_id' => 'empty-b', 'claimable_now' => 0],
            ],
            ['servable_now' => 0, 'active_leases' => 1],
        );

        $this->assertNull($result['selected_class']);
        $this->assertSame(AgentRuntimeRegistryLoadBalancingPolicy::CLASS_ROUTE_REASON_NO_CLAIMABLE_CLASS, $result['reason']);
    }
}
