<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentDispatchPlannerCandidateSelector;
use App\Services\Ai\SelfConstruction\AgentRuntimeRegistryRepository;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AgentDispatchPlannerCandidateSelectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_dispatch_planner_candidate_selection.v1', AgentDispatchPlannerCandidateSelector::SCHEMA_VERSION);
        $this->assertSame('read_only_agent_dispatch_planner_candidate_selection', AgentDispatchPlannerCandidateSelector::MODE);
        $this->assertContains('claimable', AgentDispatchPlannerCandidateSelector::CLAIMABLE_STATUSES);
        $this->assertContains('queued', AgentDispatchPlannerCandidateSelector::CLAIMABLE_STATUSES);
        $this->assertContains('released', AgentDispatchPlannerCandidateSelector::CLAIMABLE_STATUSES);
        $this->assertContains('lease_expired', AgentDispatchPlannerCandidateSelector::CLAIMABLE_STATUSES);
        $this->assertContains('available', AgentDispatchPlannerCandidateSelector::ELIGIBLE_AGENT_STATUSES);
        $this->assertContains('registered', AgentDispatchPlannerCandidateSelector::ELIGIBLE_AGENT_STATUSES);
    }

    public function test_selects_claimable_tasks_and_eligible_agents(): void
    {
        $this->seedTask('tp-1', 'claimable', 5);
        $this->seedTask('tp-2', 'blocked', 5);
        $this->seedAgent('agent-a', 'available');
        $this->seedAgent('agent-b', 'busy');
        $selector = new AgentDispatchPlannerCandidateSelector;
        $result = $selector->select();
        $this->assertSame(1, $result['task_summary']['candidate_task_count']);
        $this->assertSame(1, $result['agent_summary']['candidate_agent_count']);
        $this->assertSame('tp-1', $result['candidate_tasks'][0]['task_packet_id']);
        $this->assertSame('agent-a', $result['candidate_agents'][0]['agent_id']);
        $this->assertFalse($result['dispatch_allowed']);
    }

    public function test_status_filter_blocks_invalid(): void
    {
        $this->seedTask('tp-1', 'claimable', 5);
        $selector = new AgentDispatchPlannerCandidateSelector;
        $result = $selector->select(['task_status' => 'unknown']);
        $this->assertSame(0, $result['task_summary']['candidate_task_count']);
    }

    public function test_min_priority_filter(): void
    {
        $this->seedTask('tp-1', 'claimable', 5);
        $this->seedTask('tp-2', 'claimable', 9);
        $selector = new AgentDispatchPlannerCandidateSelector;
        $result = $selector->select(['min_priority' => 8]);
        $this->assertSame(1, $result['task_summary']['candidate_task_count']);
        $this->assertSame('tp-2', $result['candidate_tasks'][0]['task_packet_id']);
    }

    public function test_capability_filter(): void
    {
        $this->seedAgent('agent-a', 'available', ['code_edit']);
        $this->seedAgent('agent-b', 'available', ['docs_writer']);
        $selector = new AgentDispatchPlannerCandidateSelector;
        $result = $selector->select(['capability' => 'docs_writer']);
        $this->assertSame(1, $result['agent_summary']['candidate_agent_count']);
        $this->assertSame('agent-b', $result['candidate_agents'][0]['agent_id']);
    }

    public function test_kind_filter(): void
    {
        $this->seedAgent('agent-codex', 'available', ['code_edit'], 'codex');
        $this->seedAgent('agent-dry', 'available', ['dry_run_only'], 'dry_run_agent');
        $selector = new AgentDispatchPlannerCandidateSelector;
        $result = $selector->select(['agent_kind' => 'dry_run_agent']);
        $this->assertSame(1, $result['agent_summary']['candidate_agent_count']);
        $this->assertSame('agent-dry', $result['candidate_agents'][0]['agent_id']);
    }

    public function test_task_limit_caps_output(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->seedTask('tp-'.$i, 'claimable', $i);
        }
        $selector = new AgentDispatchPlannerCandidateSelector;
        $result = $selector->select(['task_limit' => 2]);
        $this->assertSame(2, count($result['candidate_tasks']));
    }

    public function test_agent_limit_caps_output(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->seedAgent('agent-'.$i, 'available');
        }
        $selector = new AgentDispatchPlannerCandidateSelector;
        $result = $selector->select(['agent_limit' => 3]);
        $this->assertSame(3, count($result['candidate_agents']));
    }

    public function test_tasks_sorted_by_priority_then_id(): void
    {
        $this->seedTask('tp-z', 'claimable', 9);
        $this->seedTask('tp-a', 'claimable', 9);
        $this->seedTask('tp-m', 'claimable', 5);
        $selector = new AgentDispatchPlannerCandidateSelector;
        $result = $selector->select();
        $this->assertSame('tp-a', $result['candidate_tasks'][0]['task_packet_id']);
        $this->assertSame('tp-z', $result['candidate_tasks'][1]['task_packet_id']);
        $this->assertSame('tp-m', $result['candidate_tasks'][2]['task_packet_id']);
    }

    public function test_selection_hash_stable(): void
    {
        $this->seedTask('tp-1', 'claimable', 5);
        $this->seedAgent('agent-a', 'available');
        $selector = new AgentDispatchPlannerCandidateSelector;
        $a = $selector->select();
        $b = $selector->select();
        $this->assertSame($a['selection_hash'], $b['selection_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a['selection_hash']);
    }

    public function test_runtime_flags_helper(): void
    {
        $selector = new AgentDispatchPlannerCandidateSelector;
        foreach ($selector->runtimeFlags() as $key => $value) {
            $this->assertFalse($value, "flag {$key} must be false");
        }
    }

    public function test_empty_state_returns_empty_lists(): void
    {
        $selector = new AgentDispatchPlannerCandidateSelector;
        $result = $selector->select();
        $this->assertSame([], $result['candidate_tasks']);
        $this->assertSame([], $result['candidate_agents']);
    }

    public function test_runtime_safety_in_envelope(): void
    {
        $selector = new AgentDispatchPlannerCandidateSelector;
        $result = $selector->select();
        $this->assertFalse($result['runtime_execution_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['self_programming_allowed']);
        $this->assertFalse($result['ledger_write_allowed']);
        $this->assertFalse($result['claim_real_allowed']);
    }

    public function test_lease_expired_treated_as_claimable(): void
    {
        $this->seedTask('tp-x', 'lease_expired', 5);
        $selector = new AgentDispatchPlannerCandidateSelector;
        $result = $selector->select();
        $this->assertSame(1, $result['task_summary']['candidate_task_count']);
    }

    public function test_released_status_treated_as_claimable(): void
    {
        $this->seedTask('tp-r', 'released', 5);
        $selector = new AgentDispatchPlannerCandidateSelector;
        $result = $selector->select();
        $this->assertSame(1, $result['task_summary']['candidate_task_count']);
    }

    public function test_evidence_refs_surfaced_from_packet(): void
    {
        $repo = new AgentControlPlaneTaskPacketQueueRepository;
        $repo->enqueue([
            'task_packet_id' => 'tp-ev',
            'task_packet_hash' => hash('sha256', 'tp-ev'),
            'status' => 'claimable',
            'required_capabilities' => ['code_edit'],
            'evidence_required' => true,
            'evidence_refs' => ['ev1.md', 'ev2.md'],
        ], ['priority' => 5]);
        $selector = new AgentDispatchPlannerCandidateSelector;
        $result = $selector->select();
        $this->assertContains('ev1.md', $result['candidate_tasks'][0]['evidence_refs']);
        $this->assertContains('ev2.md', $result['candidate_tasks'][0]['evidence_refs']);
    }

    public function test_status_counts_present(): void
    {
        $this->seedTask('tp-1', 'claimable', 5);
        $this->seedAgent('agent-a', 'available');
        $this->seedAgent('agent-b', 'registered');
        $selector = new AgentDispatchPlannerCandidateSelector;
        $result = $selector->select();
        $this->assertArrayHasKey('task_status_counts', $result['task_summary']);
        $this->assertArrayHasKey('agent_kind_counts', $result['agent_summary']);
        $this->assertArrayHasKey('agent_status_counts', $result['agent_summary']);
    }

    private function seedTask(string $id, string $status, int $priority): void
    {
        $repo = new AgentControlPlaneTaskPacketQueueRepository;
        $repo->enqueue([
            'task_packet_id' => $id,
            'task_packet_hash' => hash('sha256', $id),
            'status' => $status === 'claimable' || $status === 'queued' || $status === 'released' || $status === 'lease_expired' ? $status : 'blocked',
            'required_capabilities' => ['code_edit'],
            'risk_level' => 'low',
            'evidence_required' => true,
        ], [
            'priority' => $priority,
        ]);
    }

    /**
     * @param  array<int, string>  $capabilities
     */
    private function seedAgent(string $id, string $status, array $capabilities = ['code_edit'], string $kind = 'codex'): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $repo->register([
            'agent_id' => $id,
            'kind' => $kind,
            'label' => $id,
            'status' => $status,
            'capabilities' => $capabilities,
            'max_parallel_tasks' => 2,
            'current_task_count' => 0,
            'heartbeat_required' => true,
            'lease_supported' => true,
            'workspace_isolation_supported' => true,
            'evidence_required' => true,
        ]);
    }
}
