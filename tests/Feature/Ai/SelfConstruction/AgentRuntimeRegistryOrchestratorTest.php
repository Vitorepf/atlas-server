<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentRuntimeRegistryHeartbeatRepository;
use App\Services\Ai\SelfConstruction\AgentRuntimeRegistryOrchestrator;
use App\Services\Ai\SelfConstruction\AgentRuntimeRegistryQuarantineRepository;
use App\Services\Ai\SelfConstruction\AgentRuntimeRegistryRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AgentRuntimeRegistryOrchestratorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_register_and_heartbeat(): void
    {
        $orch = new AgentRuntimeRegistryOrchestrator;
        $result = $orch->registerAndHeartbeat(
            $this->agentPayload('agent-a'),
            ['status' => 'healthy', 'current_task_count' => 0, 'max_parallel_tasks' => 2],
        );
        $this->assertSame('register_and_heartbeat', $result['event']);
        $this->assertSame('ok', $result['registry']['status']);
        $this->assertSame('ok', $result['heartbeat']['status']);
        $this->assertFalse($result['dispatch_allowed']);
    }

    public function test_health_reports_available_when_storage_clean(): void
    {
        $orch = new AgentRuntimeRegistryOrchestrator;
        $orch->registerAndHeartbeat(
            $this->agentPayload('agent-a'),
            ['status' => 'healthy'],
        );
        $health = $orch->health();
        $this->assertSame('available', $health['status']);
        $this->assertSame(1, $health['registry_summary']['total_agents']);
        $this->assertSame(0, $health['quarantine_summary']['active_agent_count']);
        $this->assertFalse($health['runtime_execution_allowed']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $health['orchestrator_hash']);
    }

    public function test_plan_assignment(): void
    {
        $orch = new AgentRuntimeRegistryOrchestrator;
        $orch->registerAndHeartbeat(
            $this->agentPayload('agent-a', ['status' => 'available']),
            ['status' => 'healthy', 'observed_at' => CarbonImmutable::now()->toIso8601String()],
        );
        $plan = $orch->planAssignment([
            'task_packet_id' => 'tp',
            'task_packet_hash' => str_repeat('a', 64),
            'required_capabilities' => ['code_edit'],
            'risk_level' => 'low',
            'dry_run_only' => true,
        ]);
        $this->assertSame('planned', $plan['status']);
        $this->assertSame('agent-a', $plan['best_candidate']['agent_id'] ?? null);
        $this->assertFalse($plan['dispatch_allowed']);
        $this->assertFalse($plan['runtime_execution_allowed']);
    }

    public function test_plan_assignment_blocks_without_agents(): void
    {
        $orch = new AgentRuntimeRegistryOrchestrator;
        $plan = $orch->planAssignment(['task_packet_id' => 'tp', 'required_capabilities' => ['code_edit']]);
        $this->assertSame('blocked', $plan['status']);
        $this->assertContains('no_agents_registered', $plan['blockers']);
    }

    public function test_plan_assignment_respects_quarantine(): void
    {
        $orch = new AgentRuntimeRegistryOrchestrator;
        $orch->registerAndHeartbeat(
            $this->agentPayload('agent-a', ['status' => 'available']),
            ['status' => 'healthy'],
        );
        (new AgentRuntimeRegistryQuarantineRepository)->quarantine('agent-a', [
            'code' => 'operator_disabled',
            'declared_by' => 'operator-1',
        ]);
        $plan = $orch->planAssignment([
            'task_packet_id' => 'tp',
            'required_capabilities' => ['code_edit'],
        ]);
        $this->assertSame('blocked', $plan['status']);
        $this->assertContains('no_available_agents', $plan['blockers']);
        $this->assertContains('quarantined_agents_present', $plan['warnings']);
    }

    public function test_plan_handoff_uses_registry_records(): void
    {
        $orch = new AgentRuntimeRegistryOrchestrator;
        $orch->registerAndHeartbeat(
            $this->agentPayload('from-agent', ['status' => 'available']),
            ['status' => 'healthy'],
        );
        $orch->registerAndHeartbeat(
            $this->agentPayload('to-agent', ['status' => 'available']),
            ['status' => 'healthy'],
        );
        $result = $orch->planHandoff('from-agent', 'to-agent', [
            'task_packet_id' => 'tp',
            'task_packet_hash' => str_repeat('a', 64),
            'required_capabilities' => ['code_edit'],
            'evidence_refs' => ['ev1.md'],
            'risk_level' => 'low',
        ], ['continuation_summary_hash' => str_repeat('a', 64)]);
        $this->assertSame('handoff_plan', $result['event']);
        $this->assertSame('planned', $result['plan']['status']);
        $this->assertFalse($result['handoff_execution_allowed']);
    }

    public function test_plan_handoff_blocks_for_quarantined_to(): void
    {
        $orch = new AgentRuntimeRegistryOrchestrator;
        $orch->registerAndHeartbeat($this->agentPayload('from-agent'), ['status' => 'healthy']);
        $orch->registerAndHeartbeat($this->agentPayload('to-agent'), ['status' => 'healthy']);
        (new AgentRuntimeRegistryQuarantineRepository)->quarantine('to-agent', [
            'code' => 'operator_disabled',
            'declared_by' => 'op',
        ]);
        $result = $orch->planHandoff('from-agent', 'to-agent', [
            'task_packet_id' => 'tp',
            'task_packet_hash' => str_repeat('a', 64),
            'required_capabilities' => ['code_edit'],
            'evidence_refs' => ['ev1.md'],
        ], ['continuation_summary_hash' => str_repeat('a', 64)]);
        $this->assertSame('blocked', $result['plan']['status']);
        $this->assertContains('to_agent_quarantined', $result['plan']['blockers']);
    }

    public function test_runtime_flags_helper(): void
    {
        $orch = new AgentRuntimeRegistryOrchestrator;
        foreach ($orch->runtimeFlags() as $key => $value) {
            $this->assertFalse($value, "flag {$key} must remain false");
        }
    }

    public function test_health_unavailable_signal_does_not_throw(): void
    {
        $orch = new AgentRuntimeRegistryOrchestrator;
        $health = $orch->health();
        $this->assertIsArray($health);
        $this->assertContains($health['status'], ['available', 'degraded']);
    }

    public function test_register_and_heartbeat_failure_propagates(): void
    {
        $orch = new AgentRuntimeRegistryOrchestrator;
        $result = $orch->registerAndHeartbeat(
            ['agent_id' => '', 'kind' => 'codex'],
            ['status' => 'healthy'],
        );
        $this->assertSame('blocked', $result['registry']['status']);
        $this->assertNull($result['heartbeat']);
    }

    public function test_plan_assignment_capacity_summary_present(): void
    {
        $orch = new AgentRuntimeRegistryOrchestrator;
        $orch->registerAndHeartbeat($this->agentPayload('agent-aa'), ['status' => 'healthy']);
        $orch->registerAndHeartbeat($this->agentPayload('agent-bb'), ['status' => 'healthy']);
        $plan = $orch->planAssignment([
            'task_packet_id' => 'tp',
            'required_capabilities' => ['code_edit'],
            'dry_run_only' => true,
        ]);
        $this->assertSame(2, $plan['availability_summary']['total_agents']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function agentPayload(string $id, array $overrides = []): array
    {
        return array_merge([
            'agent_id' => $id,
            'kind' => 'dry_run_agent',
            'label' => $id,
            'status' => 'available',
            'capabilities' => ['code_edit', 'evidence_collection'],
            'max_parallel_tasks' => 2,
            'current_task_count' => 0,
            'heartbeat_required' => true,
            'workspace_isolation_supported' => true,
            'lease_supported' => true,
            'continuation_summary_supported' => true,
            'evidence_required' => true,
        ], $overrides);
    }

    public function test_repository_isolation(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $hb = new AgentRuntimeRegistryHeartbeatRepository;
        $this->assertTrue($repo->isAvailable());
        $this->assertTrue($hb->isAvailable());
    }
}
