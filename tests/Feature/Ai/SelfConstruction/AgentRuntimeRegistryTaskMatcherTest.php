<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentRuntimeRegistryTaskMatcher;
use Tests\TestCase;

final class AgentRuntimeRegistryTaskMatcherTest extends TestCase
{
    public function test_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_runtime_registry_task_match.v1', AgentRuntimeRegistryTaskMatcher::SCHEMA_VERSION);
        $this->assertSame('read_only_agent_runtime_registry_task_match', AgentRuntimeRegistryTaskMatcher::MODE);
    }

    public function test_best_candidate_selected_by_capability(): void
    {
        $matcher = new AgentRuntimeRegistryTaskMatcher;
        $result = $matcher->match($this->task(), [
            $this->agent('agent-low', ['capabilities' => ['code_edit']]),
            $this->agent('agent-high', ['capabilities' => ['code_edit', 'evidence_collection']]),
        ]);
        $this->assertSame('agent-high', $result['best_candidate']['agent_id']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['runtime_execution_allowed']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['match_hash']);
    }

    public function test_missing_capability_rejected(): void
    {
        $matcher = new AgentRuntimeRegistryTaskMatcher;
        $result = $matcher->match($this->task(['required_capabilities' => ['cost_reporting']]), [
            $this->agent('agent-no-cap', ['capabilities' => ['code_edit']]),
        ]);
        $this->assertSame([], $result['candidate_agents']);
        $this->assertContains('missing_capabilities', $result['rejected_agents'][0]['rejections']);
    }

    public function test_capacity_full_rejected(): void
    {
        $matcher = new AgentRuntimeRegistryTaskMatcher;
        $result = $matcher->match($this->task(), [
            $this->agent('agent-full', ['max_parallel_tasks' => 1, 'current_task_count' => 1]),
        ]);
        $this->assertContains('capacity_full', $result['rejected_agents'][0]['rejections']);
    }

    public function test_high_risk_requires_human_approval(): void
    {
        $matcher = new AgentRuntimeRegistryTaskMatcher;
        $task = $this->task(['risk_level' => 'high']);
        $resultRejected = $matcher->match($task, [
            $this->agent('agent-noop', ['capabilities' => ['code_edit', 'evidence_collection']]),
        ]);
        $this->assertContains('human_approval_required_for_high_risk', $resultRejected['rejected_agents'][0]['rejections']);

        $resultOk = $matcher->match($task, [
            $this->agent('agent-human', ['capabilities' => ['code_edit', 'evidence_collection', 'human_approval']]),
        ]);
        $this->assertSame('agent-human', $resultOk['best_candidate']['agent_id']);
        $this->assertTrue($resultOk['risk_match']['requires_human_approval']);
    }

    public function test_dry_run_only_policy(): void
    {
        $matcher = new AgentRuntimeRegistryTaskMatcher;
        $taskNotDry = $this->task(['dry_run_only' => false]);
        $taskDry = $this->task(['dry_run_only' => true]);
        $agentDry = $this->agent('agent-dr', ['kind' => 'dry_run_agent', 'capabilities' => ['code_edit', 'evidence_collection', 'dry_run_only']]);
        $rejected = $matcher->match($taskNotDry, [$agentDry]);
        $this->assertContains('dry_run_agent_requires_dry_run_only_task', $rejected['rejected_agents'][0]['rejections']);
        $ok = $matcher->match($taskDry, [$agentDry]);
        $this->assertSame('agent-dr', $ok['best_candidate']['agent_id']);
    }

    public function test_workspace_policy_requires_workspace_capability(): void
    {
        $matcher = new AgentRuntimeRegistryTaskMatcher;
        $task = $this->task(['workspace_policy' => 'isolated']);
        $result = $matcher->match($task, [
            $this->agent('agent-no-ws', [
                'capabilities' => ['code_edit', 'evidence_collection'],
                'workspace_isolation_supported' => false,
            ]),
            $this->agent('agent-ws', [
                'capabilities' => ['code_edit', 'evidence_collection', 'workspace_isolation'],
                'workspace_isolation_supported' => true,
            ]),
        ]);
        $this->assertSame('agent-ws', $result['best_candidate']['agent_id']);
        $rejectedIds = array_map(static fn (array $r): string => (string) $r['agent_id'], (array) $result['rejected_agents']);
        $this->assertContains('agent-no-ws', $rejectedIds);
    }

    public function test_lease_requirement_rejects_agents_without_support(): void
    {
        $matcher = new AgentRuntimeRegistryTaskMatcher;
        $task = $this->task(['requires_lease' => true]);
        $result = $matcher->match($task, [
            $this->agent('agent-no-lease', ['lease_supported' => false]),
        ]);
        $this->assertContains('lease_support_required', $result['rejected_agents'][0]['rejections']);
    }

    public function test_least_loaded_policy(): void
    {
        $matcher = new AgentRuntimeRegistryTaskMatcher;
        $result = $matcher->match(
            $this->task(),
            [
                $this->agent('agent-low', ['max_parallel_tasks' => 4, 'current_task_count' => 3]),
                $this->agent('agent-empty', ['max_parallel_tasks' => 4, 'current_task_count' => 0]),
            ],
            ['matching_policy' => 'least_loaded'],
        );
        $this->assertSame('agent-empty', $result['best_candidate']['agent_id']);
    }

    public function test_risk_first_human_policy(): void
    {
        $matcher = new AgentRuntimeRegistryTaskMatcher;
        $result = $matcher->match(
            $this->task(['risk_level' => 'critical']),
            [
                $this->agent('agent-human', ['capabilities' => ['code_edit', 'evidence_collection', 'human_approval']]),
            ],
            ['matching_policy' => 'risk_first_human'],
        );
        $this->assertSame('agent-human', $result['best_candidate']['agent_id']);
    }

    public function test_dispatch_allowed_false(): void
    {
        $matcher = new AgentRuntimeRegistryTaskMatcher;
        $result = $matcher->match($this->task(), [$this->agent('agent-a')]);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['runtime_execution_allowed']);
        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['self_programming_allowed']);
        $this->assertFalse($result['ledger_write_allowed']);
    }

    public function test_match_hash_stable_with_same_inputs(): void
    {
        $matcher = new AgentRuntimeRegistryTaskMatcher;
        $a = $matcher->match($this->task(), [$this->agent('agent-a')]);
        $b = $matcher->match($this->task(), [$this->agent('agent-a')]);
        $this->assertSame($a['match_hash'], $b['match_hash']);
    }

    public function test_runtime_flags_helper(): void
    {
        $matcher = new AgentRuntimeRegistryTaskMatcher;
        foreach ($matcher->runtimeFlags() as $key => $value) {
            $this->assertFalse($value, "flag {$key} must remain false");
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function task(array $overrides = []): array
    {
        return array_merge([
            'task_packet_id' => 'tp-1',
            'task_packet_hash' => str_repeat('a', 64),
            'required_capabilities' => ['code_edit', 'evidence_collection'],
            'risk_level' => 'low',
            'workspace_policy' => 'none',
            'requires_lease' => false,
            'dry_run_only' => false,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function agent(string $id, array $overrides = []): array
    {
        return array_merge([
            'agent_id' => $id,
            'kind' => 'codex',
            'status' => 'available',
            'capabilities' => ['code_edit', 'evidence_collection'],
            'max_parallel_tasks' => 2,
            'current_task_count' => 0,
            'workspace_isolation_supported' => true,
            'lease_supported' => true,
        ], $overrides);
    }
}
