<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentRuntimeRegistryAvailabilityPlanner;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class AgentRuntimeRegistryAvailabilityPlannerTest extends TestCase
{
    public function test_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_runtime_registry_availability_plan.v1', AgentRuntimeRegistryAvailabilityPlanner::SCHEMA_VERSION);
        $this->assertSame('read_only_agent_runtime_registry_availability_plan', AgentRuntimeRegistryAvailabilityPlanner::MODE);
    }

    public function test_available_agent_selected(): void
    {
        $planner = new AgentRuntimeRegistryAvailabilityPlanner;
        $plan = $planner->plan([
            $this->agent('agent-a', ['status' => 'available']),
        ], [
            $this->heartbeat('agent-a', '-10 seconds'),
        ], ['ttl_seconds' => 60]);
        $this->assertSame(1, $plan['capacity_summary']['available_count']);
        $this->assertSame(0, $plan['capacity_summary']['unavailable_count']);
        $this->assertSame('agent-a', $plan['available_agents'][0]['agent_id']);
        $this->assertFalse($plan['dispatch_allowed']);
        $this->assertFalse($plan['runtime_execution_allowed']);
    }

    public function test_busy_status_unavailable(): void
    {
        $planner = new AgentRuntimeRegistryAvailabilityPlanner;
        $plan = $planner->plan([
            $this->agent('agent-busy', ['status' => 'busy']),
        ], [
            $this->heartbeat('agent-busy'),
        ]);
        $this->assertSame(0, $plan['capacity_summary']['available_count']);
        $this->assertNotEmpty($plan['unavailable_agents']);
        $this->assertContains('busy_status', $plan['unavailable_agents'][0]['reasons']);
    }

    public function test_stale_heartbeat_unavailable(): void
    {
        $planner = new AgentRuntimeRegistryAvailabilityPlanner;
        $plan = $planner->plan([
            $this->agent('agent-stale', ['status' => 'available']),
        ], [
            $this->heartbeat('agent-stale', '-3600 seconds'),
        ], ['ttl_seconds' => 60]);
        $this->assertSame(0, $plan['capacity_summary']['available_count']);
        $this->assertSame(1, $plan['capacity_summary']['stale_count']);
        $this->assertContains('stale_heartbeat', $plan['unavailable_agents'][0]['reasons']);
    }

    public function test_missing_heartbeat_unavailable_when_required(): void
    {
        $planner = new AgentRuntimeRegistryAvailabilityPlanner;
        $plan = $planner->plan([
            $this->agent('agent-no-hb', ['status' => 'available']),
        ], []);
        $this->assertSame(0, $plan['capacity_summary']['available_count']);
        $this->assertContains('missing_heartbeat', $plan['unavailable_agents'][0]['reasons']);
    }

    public function test_heartbeat_not_required_then_available(): void
    {
        $planner = new AgentRuntimeRegistryAvailabilityPlanner;
        $plan = $planner->plan([
            $this->agent('agent-quiet', [
                'status' => 'available',
                'heartbeat_required' => false,
            ]),
        ], []);
        $this->assertSame(1, $plan['capacity_summary']['available_count']);
    }

    public function test_quarantined_blocks_availability(): void
    {
        $planner = new AgentRuntimeRegistryAvailabilityPlanner;
        $plan = $planner->plan(
            [$this->agent('agent-q', ['status' => 'available'])],
            [$this->heartbeat('agent-q')],
            ['quarantined_agents' => ['agent-q']],
        );
        $this->assertSame(0, $plan['capacity_summary']['available_count']);
        $this->assertContains('quarantined', $plan['unavailable_agents'][0]['reasons']);
    }

    public function test_capacity_full_unavailable(): void
    {
        $planner = new AgentRuntimeRegistryAvailabilityPlanner;
        $plan = $planner->plan([
            $this->agent('agent-c', [
                'status' => 'available',
                'max_parallel_tasks' => 1,
                'current_task_count' => 1,
            ]),
        ], [$this->heartbeat('agent-c')]);
        $this->assertSame(0, $plan['capacity_summary']['available_count']);
        $this->assertContains('capacity_full', $plan['unavailable_agents'][0]['reasons']);
    }

    public function test_missing_capability_unavailable(): void
    {
        $planner = new AgentRuntimeRegistryAvailabilityPlanner;
        $plan = $planner->plan(
            [$this->agent('agent-m', ['status' => 'available'])],
            [$this->heartbeat('agent-m')],
            ['required_capabilities' => ['cost_reporting']],
        );
        $this->assertSame(0, $plan['capacity_summary']['available_count']);
        $this->assertContains('missing_capabilities', $plan['unavailable_agents'][0]['reasons']);
        $this->assertContains('cost_reporting', $plan['unavailable_agents'][0]['missing_capabilities']);
    }

    public function test_workspace_isolation_required(): void
    {
        $planner = new AgentRuntimeRegistryAvailabilityPlanner;
        $plan = $planner->plan(
            [$this->agent('agent-w', ['status' => 'available', 'workspace_isolation_supported' => false])],
            [$this->heartbeat('agent-w')],
            ['require_workspace_isolation' => true],
        );
        $this->assertContains('workspace_isolation_missing', $plan['unavailable_agents'][0]['reasons']);
    }

    public function test_lease_support_required(): void
    {
        $planner = new AgentRuntimeRegistryAvailabilityPlanner;
        $plan = $planner->plan(
            [$this->agent('agent-l', ['status' => 'available', 'lease_supported' => false])],
            [$this->heartbeat('agent-l')],
            ['require_lease_support' => true],
        );
        $this->assertContains('lease_support_missing', $plan['unavailable_agents'][0]['reasons']);
    }

    public function test_availability_hash_stable(): void
    {
        $planner = new AgentRuntimeRegistryAvailabilityPlanner;
        $reference = CarbonImmutable::now()->toIso8601String();
        $a = $planner->plan(
            [$this->agent('agent-a', ['status' => 'available'])],
            [$this->heartbeat('agent-a', '-5 seconds')],
            ['reference_time' => $reference],
        );
        $b = $planner->plan(
            [$this->agent('agent-a', ['status' => 'available'])],
            [$this->heartbeat('agent-a', '-5 seconds')],
            ['reference_time' => $reference],
        );
        $this->assertSame($a['availability_hash'], $b['availability_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a['availability_hash']);
    }

    public function test_runtime_flags_helper(): void
    {
        $planner = new AgentRuntimeRegistryAvailabilityPlanner;
        foreach ($planner->runtimeFlags() as $key => $value) {
            $this->assertFalse($value, "flag {$key} must remain false");
        }
    }

    public function test_invalid_heartbeat_timestamp_marks_unavailable(): void
    {
        $planner = new AgentRuntimeRegistryAvailabilityPlanner;
        $plan = $planner->plan(
            [$this->agent('agent-a', ['status' => 'available'])],
            [['agent_id' => 'agent-a', 'observed_at' => 'broken']],
            ['ttl_seconds' => 60],
        );
        $this->assertSame(0, $plan['capacity_summary']['available_count']);
        $this->assertContains('invalid_heartbeat_timestamp', $plan['unavailable_agents'][0]['reasons']);
    }

    public function test_status_disabled_unavailable(): void
    {
        $planner = new AgentRuntimeRegistryAvailabilityPlanner;
        $plan = $planner->plan(
            [$this->agent('agent-d', ['status' => 'disabled'])],
            [$this->heartbeat('agent-d')],
        );
        $this->assertContains('disabled', $plan['unavailable_agents'][0]['reasons']);
    }

    public function test_status_registered_eligible(): void
    {
        $planner = new AgentRuntimeRegistryAvailabilityPlanner;
        $plan = $planner->plan(
            [$this->agent('agent-r', ['status' => 'registered'])],
            [$this->heartbeat('agent-r')],
        );
        $this->assertSame(1, $plan['capacity_summary']['available_count']);
    }

    public function test_capacity_summary_counts_slots(): void
    {
        $planner = new AgentRuntimeRegistryAvailabilityPlanner;
        $plan = $planner->plan([
            $this->agent('a', ['status' => 'available', 'max_parallel_tasks' => 2, 'current_task_count' => 1]),
            $this->agent('b', ['status' => 'available', 'max_parallel_tasks' => 1, 'current_task_count' => 0]),
        ], [$this->heartbeat('a'), $this->heartbeat('b')]);
        $this->assertSame(3, $plan['capacity_summary']['total_slots']);
        $this->assertSame(1, $plan['capacity_summary']['used_slots']);
        $this->assertSame(2, $plan['capacity_summary']['free_slots_available']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function agent(string $id, array $overrides = []): array
    {
        return array_merge([
            'agent_id' => $id,
            'kind' => 'dry_run_agent',
            'status' => 'available',
            'capabilities' => ['dry_run_only', 'evidence_collection'],
            'max_parallel_tasks' => 2,
            'current_task_count' => 0,
            'heartbeat_required' => true,
            'workspace_isolation_supported' => true,
            'lease_supported' => true,
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function heartbeat(string $id, string $offset = '-5 seconds'): array
    {
        return [
            'agent_id' => $id,
            'observed_at' => CarbonImmutable::parse($offset)->toIso8601String(),
            'status' => 'healthy',
        ];
    }
}
