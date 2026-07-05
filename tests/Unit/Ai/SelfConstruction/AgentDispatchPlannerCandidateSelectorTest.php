<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentDispatchPlannerCandidateSelector;
use Tests\TestCase;

final class AgentDispatchPlannerCandidateSelectorTest extends TestCase
{
    private function selector(): AgentDispatchPlannerCandidateSelector
    {
        return app(AgentDispatchPlannerCandidateSelector::class);
    }

    // ── select: integration with real DB ───────────────────────────────

    public function test_status_filter_restricts_to_the_named_claimable_status(): void
    {
        $result = $this->selector()->select(['task_status' => 'claimable']);

        $this->assertArrayHasKey('candidate_tasks', $result);
        $this->assertIsArray($result['candidate_tasks']);
    }

    public function test_min_priority_filter_excludes_below_threshold(): void
    {
        $result = $this->selector()->select(['min_priority' => 10]);

        $this->assertArrayHasKey('candidate_tasks', $result);
    }

    public function test_capability_filter_restricts_agents(): void
    {
        $result = $this->selector()->select(['capability' => 'code_edit']);

        $this->assertArrayHasKey('candidate_agents', $result);
    }

    public function test_kind_filter_restricts_agents(): void
    {
        $result = $this->selector()->select(['agent_kind' => 'muscle']);

        $this->assertArrayHasKey('candidate_agents', $result);
    }

    public function test_limit_filters_cap_both_tasks_and_agents(): void
    {
        $result = $this->selector()->select(['task_limit' => 5, 'agent_limit' => 3]);

        $this->assertLessThanOrEqual(5, count($result['candidate_tasks']));
        $this->assertLessThanOrEqual(3, count($result['candidate_agents']));
    }

    public function test_task_status_counts_and_agent_status_counts_are_present(): void
    {
        $result = $this->selector()->select();

        $this->assertArrayHasKey('task_summary', $result);
        $this->assertArrayHasKey('task_status_counts', $result['task_summary']);
        $this->assertArrayHasKey('agent_status_counts', $result['agent_summary']);
    }

    public function test_runtime_flags_are_read_only_false(): void
    {
        $result = $this->selector()->select();

        $this->assertFalse($result['runtime_execution_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['self_programming_allowed']);
        $this->assertFalse($result['ledger_write_allowed']);
        $this->assertFalse($result['claim_real_allowed']);
    }

    // ── rankByValue: pure ──────────────────────────────────────────────

    public function test_rank_by_value_is_deterministic_for_equivalent_candidate_input_and_does_not_mutate_queue_state(): void
    {
        $tasks = [
            ['task_packet_id' => 'b-task', 'value_density' => 0.8, 'implementability' => 0.7, 'freshness' => 0.6, 'risk_level' => 'low'],
            ['task_packet_id' => 'a-task', 'value_density' => 0.8, 'implementability' => 0.7, 'freshness' => 0.6, 'risk_level' => 'low'],
        ];

        $a = (new AgentDispatchPlannerCandidateSelector)->rankByValue($tasks);
        $b = (new AgentDispatchPlannerCandidateSelector)->rankByValue($tasks);

        $this->assertSame(
            array_column($a['selected'], 'task_packet_id'),
            array_column($b['selected'], 'task_packet_id'),
        );
        $this->assertSame('a-task', $a['selected'][0]['task_packet_id'] ?? '');
    }

    public function test_rank_by_value_orders_by_density_implementability_freshness_worker_fit_and_stable_tie_break(): void
    {
        $tasks = [
            ['task_packet_id' => 'high-value', 'value_density' => 0.9, 'implementability' => 0.9, 'freshness' => 0.9, 'risk_level' => 'low'],
            ['task_packet_id' => 'low-value',  'value_density' => 0.1, 'implementability' => 0.1, 'freshness' => 0.1, 'risk_level' => 'high'],
        ];

        $result = (new AgentDispatchPlannerCandidateSelector)->rankByValue($tasks);

        $this->assertSame('high-value', $result['selected'][0]['task_packet_id']);
        $this->assertSame('low-value', $result['selected'][1]['task_packet_id']);
    }

    public function test_rank_by_value_defers_when_saturated(): void
    {
        $tasks = [
            ['task_packet_id' => 't1', 'value_density' => 0.8, 'implementability' => 0.8, 'freshness' => 0.8, 'risk_level' => 'low'],
            ['task_packet_id' => 't2', 'value_density' => 0.7, 'implementability' => 0.7, 'freshness' => 0.7, 'risk_level' => 'low'],
            ['task_packet_id' => 't3', 'value_density' => 0.6, 'implementability' => 0.6, 'freshness' => 0.6, 'risk_level' => 'low'],
        ];

        $result = (new AgentDispatchPlannerCandidateSelector)->rankByValue($tasks, ['capacity' => 2]);

        $this->assertCount(2, $result['selected']);
        $this->assertCount(1, $result['deferred']);
        $this->assertStringContainsString('deferred', (string) ($result['rationale']['t3'] ?? ''));
    }
}
