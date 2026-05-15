<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentRuntimeRegistryLoadBalancingPolicy;
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
}
