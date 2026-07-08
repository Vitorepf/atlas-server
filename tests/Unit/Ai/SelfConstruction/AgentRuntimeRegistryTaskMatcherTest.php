<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentRuntimeRegistryTaskMatcher;
use Tests\TestCase;

final class AgentRuntimeRegistryTaskMatcherTest extends TestCase
{
    private function service(): AgentRuntimeRegistryTaskMatcher
    {
        return new AgentRuntimeRegistryTaskMatcher;
    }

    // ── Constants ──────────────────────────────────────────────────────

    public function test_constants_canonical(): void
    {
        $this->assertSame(
            'atlas.self_construction.agent_runtime_registry_task_match.v1',
            AgentRuntimeRegistryTaskMatcher::SCHEMA_VERSION,
        );
        $this->assertSame(
            'read_only_agent_runtime_registry_task_match',
            AgentRuntimeRegistryTaskMatcher::MODE,
        );
        $this->assertSame(['low', 'medium', 'high', 'critical'], AgentRuntimeRegistryTaskMatcher::RISK_LEVELS);
    }

    // ── match ──────────────────────────────────────────────────────────

    public function test_missing_capability_rejected(): void
    {
        $result = $this->service()->match(
            ['task_packet_id' => 'tp-1', 'required_capabilities' => ['code_edit', 'reading']],
            [['agent_id' => 'agent-1', 'status' => 'available', 'capabilities' => ['only_reading']]],
        );

        $this->assertCount(0, $result['candidate_agents']);
        $this->assertCount(1, $result['rejected_agents']);
        $this->assertContains('missing_capabilities', $result['rejected_agents'][0]['rejections']);
    }

    public function test_capacity_full_rejected(): void
    {
        $result = $this->service()->match(
            ['required_capabilities' => ['code_edit']],
            [['agent_id' => 'agent-1', 'status' => 'available', 'capabilities' => ['code_edit'], 'max_parallel_tasks' => 2, 'current_task_count' => 2]],
        );

        $this->assertCount(0, $result['candidate_agents']);
        $this->assertContains('capacity_full', $result['rejected_agents'][0]['rejections']);
    }

    public function test_best_candidate_selected_by_capability(): void
    {
        $result = $this->service()->match(
            ['required_capabilities' => ['code_edit']],
            [
                ['agent_id' => 'agent-a', 'status' => 'available', 'capabilities' => ['code_edit', 'reading']],
                ['agent_id' => 'agent-b', 'status' => 'available', 'capabilities' => ['code_edit']],
            ],
        );

        $this->assertCount(2, $result['candidate_agents']);
        $best = $result['best_candidate'];
        $this->assertNotNull($best);
        // agent-a has more capabilities => higher capability_score
        $this->assertSame('agent-a', $best['agent_id']);
    }

    public function test_unavailable_worker_is_rejected_with_status_reason(): void
    {
        $result = $this->service()->match(
            ['required_capabilities' => ['code_edit']],
            [['agent_id' => 'agent-1', 'status' => 'offline', 'capabilities' => ['code_edit']]],
        );

        $this->assertCount(0, $result['candidate_agents']);
        $this->assertStringContainsString('status_not_eligible', $result['rejected_agents'][0]['rejections'][0]);
    }

    public function test_high_risk_requires_human_approval(): void
    {
        $result = $this->service()->match(
            ['required_capabilities' => ['code_edit'], 'risk_level' => 'high'],
            [['agent_id' => 'agent-1', 'status' => 'available', 'capabilities' => ['code_edit']]],
        );

        $this->assertContains('human_approval_required_for_high_risk', $result['rejected_agents'][0]['rejections']);
    }

    public function test_dry_run_only_policy(): void
    {
        $result = $this->service()->match(
            ['required_capabilities' => ['code_edit'], 'dry_run_only' => false],
            [['agent_id' => 'agent-1', 'status' => 'available', 'kind' => 'dry_run_agent', 'capabilities' => ['code_edit']]],
        );

        $this->assertContains('dry_run_agent_requires_dry_run_only_task', $result['rejected_agents'][0]['rejections']);
    }

    public function test_workspace_policy_requires_workspace_capability(): void
    {
        $result = $this->service()->match(
            ['required_capabilities' => ['code_edit'], 'workspace_policy' => 'isolated'],
            [['agent_id' => 'agent-1', 'status' => 'available', 'capabilities' => ['code_edit'], 'workspace_isolation_supported' => false]],
        );

        $this->assertContains('workspace_isolation_required', $result['rejected_agents'][0]['rejections']);
    }

    public function test_lease_requirement_rejects_agents_without_support(): void
    {
        $result = $this->service()->match(
            ['required_capabilities' => ['code_edit'], 'requires_lease' => true],
            [['agent_id' => 'agent-1', 'status' => 'available', 'capabilities' => ['code_edit'], 'lease_supported' => false]],
        );

        $this->assertContains('lease_support_required', $result['rejected_agents'][0]['rejections']);
    }

    public function test_least_loaded_policy(): void
    {
        $result = $this->service()->match(
            ['required_capabilities' => ['code_edit']],
            [
                ['agent_id' => 'busy', 'status' => 'available', 'capabilities' => ['code_edit'], 'max_parallel_tasks' => 5, 'current_task_count' => 4],
                ['agent_id' => 'free', 'status' => 'available', 'capabilities' => ['code_edit'], 'max_parallel_tasks' => 5, 'current_task_count' => 0],
            ],
            ['matching_policy' => 'least_loaded'],
        );

        $this->assertSame('free', $result['best_candidate']['agent_id']);
    }

    public function test_risk_first_human_policy(): void
    {
        $result = $this->service()->match(
            ['required_capabilities' => ['code_edit'], 'risk_level' => 'critical'],
            [
                ['agent_id' => 'human', 'status' => 'available', 'capabilities' => ['code_edit', 'human_approval']],
                ['agent_id' => 'bot', 'status' => 'available', 'capabilities' => ['code_edit']],
            ],
            ['matching_policy' => 'risk_first_human'],
        );

        $this->assertSame('human', $result['best_candidate']['agent_id']);
    }

    public function test_dispatch_allowed_false(): void
    {
        $result = $this->service()->match(
            ['required_capabilities' => ['code_edit']],
            [['agent_id' => 'agent-1', 'status' => 'available', 'capabilities' => ['code_edit']]],
        );

        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['runtime_execution_allowed']);
        $this->assertFalse($result['provider_call_allowed']);
    }

    public function test_match_hash_stable_with_same_inputs(): void
    {
        $task = ['required_capabilities' => ['code_edit']];
        $agents = [['agent_id' => 'agent-1', 'status' => 'available', 'capabilities' => ['code_edit']]];

        $a = $this->service()->match($task, $agents);
        $b = $this->service()->match($task, $agents);

        $this->assertSame($a['match_hash'], $b['match_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a['match_hash']);
    }

    // ── runtimeFlags ───────────────────────────────────────────────────

    public function test_runtime_flags_helper(): void
    {
        $flags = $this->service()->runtimeFlags();

        $this->assertFalse($flags['runtime_execution_allowed']);
        $this->assertFalse($flags['dispatch_allowed']);
        $this->assertFalse($flags['provider_call_allowed']);
        $this->assertFalse($flags['token_spend_allowed']);
        $this->assertFalse($flags['self_programming_allowed']);
        $this->assertFalse($flags['ledger_write_allowed']);
    }

    // ── selectBestFit (pure) ───────────────────────────────────────────

    public function test_selects_worker_with_highest_fit_score(): void
    {
        $result = $this->service()->selectBestFit(
            ['task_family' => 'code_patch'],
            [
                ['agent_id' => 'low-fit', 'status' => 'available', 'task_family_experience' => ['code_patch' => 0.9], 'recent_success_rate' => 0.9, 'give_back_rate' => 0.0, 'max_parallel_tasks' => 5, 'current_task_count' => 0],
                ['agent_id' => 'lower', 'status' => 'available', 'task_family_experience' => ['code_patch' => 0.5], 'recent_success_rate' => 0.5, 'give_back_rate' => 0.3, 'max_parallel_tasks' => 5, 'current_task_count' => 2],
            ],
        );

        $this->assertSame('low-fit', $result['selected_worker']['agent_id']);
        $this->assertStringContainsString('low-fit', $result['rationale']);
    }

    public function test_unavailable_worker_is_rejected_with_status_reason_select(): void
    {
        $result = $this->service()->selectBestFit(
            ['task_family' => 'code_patch'],
            [['agent_id' => 'offline', 'status' => 'offline']],
        );

        $this->assertNull($result['selected_worker']);
        $this->assertSame('offline', $result['rejected_workers'][0]['agent_id']);
    }

    public function test_poor_fit_available_worker_is_suppressed_despite_availability(): void
    {
        $result = $this->service()->selectBestFit(
            ['task_family' => 'code_patch'],
            [['agent_id' => 'poor', 'status' => 'available', 'task_family_experience' => ['code_patch' => 0.0], 'recent_success_rate' => 0.0, 'give_back_rate' => 1.0, 'max_parallel_tasks' => 5, 'current_task_count' => 0]],
        );

        $this->assertNull($result['selected_worker']);
    }

    public function test_high_give_back_rate_reduces_fit_score(): void
    {
        $result = $this->service()->selectBestFit(
            ['task_family' => 'code_patch'],
            [
                ['agent_id' => 'low-gb', 'status' => 'available', 'task_family_experience' => ['code_patch' => 0.5], 'recent_success_rate' => 0.5, 'give_back_rate' => 0.0, 'max_parallel_tasks' => 5, 'current_task_count' => 0],
                ['agent_id' => 'high-gb', 'status' => 'available', 'task_family_experience' => ['code_patch' => 0.5], 'recent_success_rate' => 0.5, 'give_back_rate' => 0.9, 'max_parallel_tasks' => 5, 'current_task_count' => 0],
            ],
        );

        $this->assertSame('low-gb', $result['selected_worker']['agent_id']);
    }

    public function test_active_load_reduces_fit_score(): void
    {
        $result = $this->service()->selectBestFit(
            ['task_family' => 'code_patch'],
            [
                ['agent_id' => 'idle', 'status' => 'available', 'task_family_experience' => ['code_patch' => 0.5], 'recent_success_rate' => 0.5, 'give_back_rate' => 0.0, 'max_parallel_tasks' => 5, 'current_task_count' => 0],
                ['agent_id' => 'loaded', 'status' => 'available', 'task_family_experience' => ['code_patch' => 0.5], 'recent_success_rate' => 0.5, 'give_back_rate' => 0.0, 'max_parallel_tasks' => 5, 'current_task_count' => 5],
            ],
        );

        $this->assertSame('idle', $result['selected_worker']['agent_id']);
    }

    public function test_no_agents_yields_no_selection(): void
    {
        $result = $this->service()->selectBestFit(['task_family' => 'code_patch'], []);

        $this->assertNull($result['selected_worker']);
        $this->assertSame([], $result['ranked_workers']);
        $this->assertStringContainsString('no_eligible', $result['rationale']);
    }

    public function test_select_best_fit_never_allows_dispatch(): void
    {
        $result = $this->service()->selectBestFit(
            ['task_family' => 'code_patch'],
            [['agent_id' => 'agent-1', 'status' => 'available', 'task_family_experience' => ['code_patch' => 0.9], 'recent_success_rate' => 0.9, 'give_back_rate' => 0.0, 'max_parallel_tasks' => 5, 'current_task_count' => 0]],
        );

        $this->assertFalse($result['dispatch_allowed']);
    }
}
