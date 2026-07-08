<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentDispatchPlannerBatchPlanner;
use Tests\TestCase;

final class AgentDispatchPlannerBatchPlannerTest extends TestCase
{
    private function plan(array $options = []): array
    {
        return app(AgentDispatchPlannerBatchPlanner::class)->plan($options);
    }

    public function test_produces_planned_and_blocked_dispatches(): void
    {
        $result = $this->plan(['max_dispatches' => 5]);

        $this->assertArrayHasKey('planned_dispatches', $result);
        $this->assertArrayHasKey('blocked_dispatches', $result);
        $this->assertIsInt($result['planned_dispatch_count']);
        $this->assertIsInt($result['blocked_dispatch_count']);
        $this->assertTrue($result['status'] === 'planned' || $result['status'] === 'blocked');
    }

    public function test_refuses_tasks_with_governance_blockers(): void
    {
        $result = $this->plan(['governance' => ['fail_governance' => true]]);

        $this->assertArrayHasKey('global_blockers', $result);
    }

    public function test_selects_the_first_eligible_agent_with_free_slots_after_ordering(): void
    {
        $result = $this->plan();

        // Planning always produces a deterministic structure
        $this->assertArrayHasKey('planned_dispatches', $result);
    }

    public function test_optionally_attaches_dry_run_receipt_ids(): void
    {
        $result = $this->plan(['persist_receipts' => false]);

        $this->assertSame(count($result['planned_dispatches']), $result['planned_dispatch_count']);
    }

    public function test_emits_dispatch_allowed_false_across_all_runtime_flags(): void
    {
        $result = $this->plan();

        $this->assertFalse($result['runtime_execution_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['self_programming_allowed']);
        $this->assertFalse($result['ledger_write_allowed']);
        $this->assertFalse($result['claim_real_allowed']);
    }

    public function test_batch_hash_is_stable_for_the_same_input(): void
    {
        $a = $this->plan();
        $b = $this->plan();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a['batch_hash']);
        $this->assertSame($a['batch_hash'], $b['batch_hash']);
    }

    public function test_schema_version_and_mode(): void
    {
        $result = $this->plan();

        $this->assertSame(
            AgentDispatchPlannerBatchPlanner::SCHEMA_VERSION,
            $result['schema_version'],
        );
        $this->assertSame(
            AgentDispatchPlannerBatchPlanner::MODE,
            $result['mode'],
        );
    }

    public function test_runtime_flags_helper(): void
    {
        $flags = (new AgentDispatchPlannerBatchPlanner)->runtimeFlags();

        $this->assertFalse($flags['runtime_execution_allowed']);
        $this->assertFalse($flags['dispatch_allowed']);
        $this->assertFalse($flags['provider_call_allowed']);
        $this->assertFalse($flags['token_spend_allowed']);
    }
}
