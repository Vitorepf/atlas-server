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

    // ── selectBestFit ────────────────────────────────────────────────────────────

    private function fitAgent(string $id, array $overrides = []): array
    {
        return array_merge([
            'agent_id' => $id,
            'status' => 'available',
            'task_family_experience' => ['external_brain' => 0.8],
            'recent_success_rate' => 0.8,
            'give_back_rate' => 0.1,
            'current_task_count' => 0,
            'max_parallel_tasks' => 3,
        ], $overrides);
    }

    public function test_selects_worker_with_highest_fit_score(): void
    {
        $matcher = new AgentRuntimeRegistryTaskMatcher;
        $result = $matcher->selectBestFit(['task_family' => 'external_brain'], [
            $this->fitAgent('weak', ['task_family_experience' => ['external_brain' => 0.1], 'recent_success_rate' => 0.2]),
            $this->fitAgent('strong', ['task_family_experience' => ['external_brain' => 0.9], 'recent_success_rate' => 0.9]),
        ]);

        $this->assertSame('strong', $result['selected_worker']['agent_id']);
        $this->assertStringContainsString('strong', $result['rationale']);
    }

    public function test_unavailable_worker_is_rejected_with_status_reason(): void
    {
        $matcher = new AgentRuntimeRegistryTaskMatcher;
        $result = $matcher->selectBestFit(['task_family' => 'external_brain'], [
            $this->fitAgent('offline', ['status' => 'offline']),
        ]);

        $this->assertNull($result['selected_worker']);
        $this->assertSame('status_not_eligible:offline', $result['rejected_workers'][0]['reason']);
    }

    public function test_poor_fit_available_worker_is_suppressed_despite_availability(): void
    {
        $matcher = new AgentRuntimeRegistryTaskMatcher;
        $result = $matcher->selectBestFit(['task_family' => 'external_brain'], [
            $this->fitAgent('poor-fit', [
                'task_family_experience' => ['external_brain' => 0.0],
                'recent_success_rate' => 0.0,
                'give_back_rate' => 0.9,
                'current_task_count' => 3,
                'max_parallel_tasks' => 3,
            ]),
        ]);

        $this->assertNull($result['selected_worker']);
        $this->assertSame('poor_fit_for_task_family', $result['rejected_workers'][0]['reason']);
        $this->assertSame('no_eligible_worker_meets_fit_threshold', $result['rationale']);
    }

    public function test_high_give_back_rate_reduces_fit_score(): void
    {
        $matcher = new AgentRuntimeRegistryTaskMatcher;
        $result = $matcher->selectBestFit(['task_family' => 'external_brain'], [
            $this->fitAgent('risky', ['give_back_rate' => 0.9]),
            $this->fitAgent('reliable', ['give_back_rate' => 0.0]),
        ]);

        $this->assertSame('reliable', $result['selected_worker']['agent_id']);
    }

    public function test_active_load_reduces_fit_score(): void
    {
        $matcher = new AgentRuntimeRegistryTaskMatcher;
        $result = $matcher->selectBestFit(['task_family' => 'external_brain'], [
            $this->fitAgent('busy', ['current_task_count' => 3, 'max_parallel_tasks' => 3]),
            $this->fitAgent('idle', ['current_task_count' => 0, 'max_parallel_tasks' => 3]),
        ]);

        $this->assertSame('idle', $result['selected_worker']['agent_id']);
    }

    public function test_no_agents_yields_no_selection(): void
    {
        $matcher = new AgentRuntimeRegistryTaskMatcher;
        $result = $matcher->selectBestFit(['task_family' => 'external_brain'], []);

        $this->assertNull($result['selected_worker']);
        $this->assertSame([], $result['ranked_workers']);
    }

    public function test_select_best_fit_never_allows_dispatch(): void
    {
        $matcher = new AgentRuntimeRegistryTaskMatcher;
        $result = $matcher->selectBestFit(['task_family' => 'external_brain'], [$this->fitAgent('a')]);

        $this->assertFalse($result['dispatch_allowed']);
    }
}
