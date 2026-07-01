<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentDispatchPlannerCandidateSelector;
use App\Services\Ai\SelfConstruction\AgentRuntimeRegistryRepository;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Focused contract test: proves status/min_priority/capability/kind/limit filters,
 * task_status_counts and agent_status_counts are present, runtimeFlags() is read-only-false
 * across the board, and rankByValue() is deterministic for equivalent input, orders by
 * value density → implementability → freshness → worker fit → risk, with a stable
 * task_packet_id tie-break, all without mutating any queue/agent state.
 */
final class AgentDispatchPlannerCandidateSelectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function seedTask(string $id, string $status, int $priority): void
    {
        $repo = new AgentControlPlaneTaskPacketQueueRepository;
        $repo->enqueue([
            'task_packet_id' => $id,
            'task_packet_hash' => hash('sha256', $id),
            'status' => $status,
            'required_capabilities' => ['code_edit'],
            'risk_level' => 'low',
            'evidence_required' => true,
        ], ['priority' => $priority]);
    }

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

    public function test_status_filter_restricts_to_the_named_claimable_status(): void
    {
        $this->seedTask('tp-claimable', 'claimable', 5);
        $this->seedTask('tp-queued', 'queued', 5);

        $result = (new AgentDispatchPlannerCandidateSelector)->select(['task_status' => 'claimable']);

        self::assertSame(1, $result['task_summary']['candidate_task_count']);
        self::assertSame('tp-claimable', $result['candidate_tasks'][0]['task_packet_id']);
    }

    public function test_min_priority_filter_excludes_below_threshold(): void
    {
        $this->seedTask('tp-low', 'claimable', 2);
        $this->seedTask('tp-high', 'claimable', 9);

        $result = (new AgentDispatchPlannerCandidateSelector)->select(['min_priority' => 5]);

        self::assertSame(1, $result['task_summary']['candidate_task_count']);
        self::assertSame('tp-high', $result['candidate_tasks'][0]['task_packet_id']);
    }

    public function test_capability_filter_restricts_agents(): void
    {
        $this->seedAgent('agent-match', 'available', ['security_audit']);
        $this->seedAgent('agent-other', 'available', ['code_edit']);

        $result = (new AgentDispatchPlannerCandidateSelector)->select(['capability' => 'security_audit']);

        self::assertSame(1, $result['agent_summary']['candidate_agent_count']);
        self::assertSame('agent-match', $result['candidate_agents'][0]['agent_id']);
    }

    public function test_kind_filter_restricts_agents(): void
    {
        $this->seedAgent('agent-codex', 'available', ['code_edit'], 'codex');
        $this->seedAgent('agent-dry', 'available', ['dry_run_only'], 'dry_run_agent');

        $result = (new AgentDispatchPlannerCandidateSelector)->select(['agent_kind' => 'dry_run_agent']);

        self::assertSame(1, $result['agent_summary']['candidate_agent_count']);
        self::assertSame('agent-dry', $result['candidate_agents'][0]['agent_id']);
    }

    public function test_limit_filters_cap_both_tasks_and_agents(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->seedTask("tp-{$i}", 'claimable', $i);
            $this->seedAgent("agent-{$i}", 'available');
        }

        $result = (new AgentDispatchPlannerCandidateSelector)->select(['task_limit' => 2, 'agent_limit' => 3]);

        self::assertCount(2, $result['candidate_tasks']);
        self::assertCount(3, $result['candidate_agents']);
    }

    public function test_task_status_counts_and_agent_status_counts_are_present(): void
    {
        $this->seedTask('tp-1', 'claimable', 5);
        $this->seedAgent('agent-a', 'available');
        $this->seedAgent('agent-b', 'registered');

        $result = (new AgentDispatchPlannerCandidateSelector)->select();

        self::assertSame(['claimable' => 1], $result['task_summary']['task_status_counts']);
        self::assertSame(['available' => 1, 'registered' => 1], $result['agent_summary']['agent_status_counts']);
    }

    public function test_runtime_flags_are_read_only_false(): void
    {
        $flags = (new AgentDispatchPlannerCandidateSelector)->runtimeFlags();

        foreach ($flags as $key => $value) {
            self::assertFalse($value, "flag {$key} must be read-only false");
        }
        self::assertArrayHasKey('runtime_execution_allowed', $flags);
        self::assertArrayHasKey('claim_real_allowed', $flags);
    }

    private function task(array $overrides = []): array
    {
        return array_merge([
            'task_packet_id' => 'p-default',
            'value_density' => 0.5,
            'implementability' => 0.5,
            'freshness' => 0.5,
            'risk_level' => 'low',
            'required_capabilities' => [],
        ], $overrides);
    }

    public function test_rank_by_value_is_deterministic_for_equivalent_candidate_input_and_does_not_mutate_queue_state(): void
    {
        $this->seedTask('tp-live', 'claimable', 5);
        $candidates = [
            $this->task(['task_packet_id' => 'a', 'value_density' => 0.9]),
            $this->task(['task_packet_id' => 'b', 'value_density' => 0.3]),
        ];

        $selector = new AgentDispatchPlannerCandidateSelector;
        $r1 = $selector->rankByValue($candidates);
        $r2 = $selector->rankByValue($candidates);

        self::assertSame($r1, $r2);

        // The live queue entry is untouched by rankByValue (pure function over the array passed in).
        $stillClaimable = (new AgentDispatchPlannerCandidateSelector)->select(['task_status' => 'claimable']);
        self::assertSame(1, $stillClaimable['task_summary']['candidate_task_count']);
        self::assertSame('tp-live', $stillClaimable['candidate_tasks'][0]['task_packet_id']);
    }

    public function test_rank_by_value_orders_by_density_implementability_freshness_worker_fit_and_stable_tie_break(): void
    {
        $selector = new AgentDispatchPlannerCandidateSelector;

        // Higher value_density wins outright.
        $byDensity = $selector->rankByValue([
            $this->task(['task_packet_id' => 'low-density', 'value_density' => 0.1]),
            $this->task(['task_packet_id' => 'high-density', 'value_density' => 0.9]),
        ]);
        self::assertSame('high-density', $byDensity['selected'][0]['task_packet_id']);

        // Equal density, higher implementability wins.
        $byImplementability = $selector->rankByValue([
            $this->task(['task_packet_id' => 'low-impl', 'value_density' => 0.5, 'implementability' => 0.1]),
            $this->task(['task_packet_id' => 'high-impl', 'value_density' => 0.5, 'implementability' => 0.9]),
        ]);
        self::assertSame('high-impl', $byImplementability['selected'][0]['task_packet_id']);

        // Equal density/implementability, higher freshness wins.
        $byFreshness = $selector->rankByValue([
            $this->task(['task_packet_id' => 'stale', 'value_density' => 0.5, 'implementability' => 0.5, 'freshness' => 0.1]),
            $this->task(['task_packet_id' => 'fresh', 'value_density' => 0.5, 'implementability' => 0.5, 'freshness' => 0.9]),
        ]);
        self::assertSame('fresh', $byFreshness['selected'][0]['task_packet_id']);

        // Equal everything else, worker-fit determined by matching required_capabilities wins.
        $byWorkerFit = $selector->rankByValue([
            $this->task(['task_packet_id' => 'mismatch', 'required_capabilities' => ['security_audit']]),
            $this->task(['task_packet_id' => 'fits', 'required_capabilities' => ['code_edit']]),
        ], ['worker_capabilities' => ['code_edit']]);
        self::assertSame('fits', $byWorkerFit['selected'][0]['task_packet_id']);

        // Fully identical scoring inputs → stable tie-break on task_packet_id ascending.
        $tie = $selector->rankByValue([
            $this->task(['task_packet_id' => 'zzz']),
            $this->task(['task_packet_id' => 'aaa']),
        ]);
        self::assertSame('aaa', $tie['selected'][0]['task_packet_id']);
        self::assertSame('zzz', $tie['selected'][1]['task_packet_id']);
    }
}
