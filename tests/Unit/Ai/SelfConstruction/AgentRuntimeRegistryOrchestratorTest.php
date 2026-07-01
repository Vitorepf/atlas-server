<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentRuntimeRegistryOrchestrator;
use App\Services\Ai\SelfConstruction\AgentRuntimeRegistryQuarantineRepository;
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

    // ── AC4: output includes chosen agent, rejected agents, proof continuity, next repair ──

    public function test_planned_assignment_includes_rejected_agents_field(): void
    {
        $orch = new AgentRuntimeRegistryOrchestrator;
        $orch->registerAndHeartbeat(
            $this->agentPayload('agent-a', ['status' => 'available']),
            ['status' => 'healthy', 'observed_at' => CarbonImmutable::now()->toIso8601String()],
        );
        (new AgentRuntimeRegistryQuarantineRepository)->quarantine('agent-b', [
            'code' => 'operator_disabled',
            'declared_by' => 'operator-1',
        ]);
        $orch->registerAndHeartbeat(
            $this->agentPayload('agent-b', ['status' => 'available']),
            ['status' => 'healthy', 'observed_at' => CarbonImmutable::now()->toIso8601String()],
        );

        $plan = $orch->planAssignment([
            'task_packet_id' => 'tp',
            'required_capabilities' => ['code_edit'],
            'dry_run_only' => true,
        ]);

        $this->assertSame('agent-a', $plan['best_candidate']['agent_id'] ?? null);
        $this->assertArrayHasKey('rejected_agents', $plan);
        $rejectedIds = array_column($plan['rejected_agents'], 'agent_id');
        $this->assertContains('agent-b', $rejectedIds);
    }

    public function test_no_continuation_context_yields_new_assignment_proof_continuity(): void
    {
        $orch = new AgentRuntimeRegistryOrchestrator;
        $orch->registerAndHeartbeat(
            $this->agentPayload('agent-a'),
            ['status' => 'healthy', 'observed_at' => CarbonImmutable::now()->toIso8601String()],
        );

        $plan = $orch->planAssignment([
            'task_packet_id' => 'tp',
            'required_capabilities' => ['code_edit'],
            'dry_run_only' => true,
        ]);

        $this->assertSame('new_assignment_no_continuity_required', $plan['proof_continuity_status']);
    }

    public function test_continuation_context_yields_continuous_proof_continuity(): void
    {
        $orch = new AgentRuntimeRegistryOrchestrator;
        $orch->registerAndHeartbeat(
            $this->agentPayload('agent-a'),
            ['status' => 'healthy', 'observed_at' => CarbonImmutable::now()->toIso8601String()],
        );

        $plan = $orch->planAssignment([
            'task_packet_id' => 'tp',
            'required_capabilities' => ['code_edit'],
            'continuation_context' => ['prior_evidence_hash' => str_repeat('a', 64)],
            'dry_run_only' => true,
        ]);

        $this->assertSame('continuous_from_prior_evidence', $plan['proof_continuity_status']);
    }

    public function test_next_repair_action_is_none_required_when_planned(): void
    {
        $orch = new AgentRuntimeRegistryOrchestrator;
        $orch->registerAndHeartbeat(
            $this->agentPayload('agent-a'),
            ['status' => 'healthy', 'observed_at' => CarbonImmutable::now()->toIso8601String()],
        );

        $plan = $orch->planAssignment([
            'task_packet_id' => 'tp',
            'required_capabilities' => ['code_edit'],
            'dry_run_only' => true,
        ]);

        $this->assertSame('planned', $plan['status']);
        $this->assertSame('none_required', $plan['next_repair_action']);
    }

    public function test_next_repair_action_when_no_agents_registered(): void
    {
        $orch = new AgentRuntimeRegistryOrchestrator;

        $plan = $orch->planAssignment(['task_packet_id' => 'tp', 'required_capabilities' => ['code_edit'], 'dry_run_only' => true]);

        $this->assertSame('blocked', $plan['status']);
        $this->assertSame('register_at_least_one_agent', $plan['next_repair_action']);
    }

    public function test_next_repair_action_when_all_agents_quarantined(): void
    {
        $orch = new AgentRuntimeRegistryOrchestrator;
        $orch->registerAndHeartbeat(
            $this->agentPayload('agent-a'),
            ['status' => 'healthy', 'observed_at' => CarbonImmutable::now()->toIso8601String()],
        );
        (new AgentRuntimeRegistryQuarantineRepository)->quarantine('agent-a', [
            'code' => 'operator_disabled',
            'declared_by' => 'operator-1',
        ]);

        $plan = $orch->planAssignment(['task_packet_id' => 'tp', 'required_capabilities' => ['code_edit'], 'dry_run_only' => true]);

        $this->assertSame('blocked', $plan['status']);
        $this->assertSame('unquarantine_or_register_a_non_quarantined_agent', $plan['next_repair_action']);
    }

    public function test_next_repair_action_when_capacity_full(): void
    {
        $orch = new AgentRuntimeRegistryOrchestrator;
        $orch->registerAndHeartbeat(
            $this->agentPayload('agent-a', ['max_parallel_tasks' => 1, 'current_task_count' => 1]),
            ['status' => 'healthy', 'observed_at' => CarbonImmutable::now()->toIso8601String()],
        );

        $plan = $orch->planAssignment(['task_packet_id' => 'tp', 'required_capabilities' => ['code_edit'], 'dry_run_only' => true]);

        $this->assertSame('blocked', $plan['status']);
        $this->assertSame('free_agent_capacity_or_register_additional_agent', $plan['next_repair_action']);
    }

    // ── AC3: orchestrator never selects a stale/overloaded/quarantined/mismatched agent ──

    public function test_selected_agent_is_never_the_quarantined_one(): void
    {
        $orch = new AgentRuntimeRegistryOrchestrator;
        $orch->registerAndHeartbeat(
            $this->agentPayload('good-agent'),
            ['status' => 'healthy', 'observed_at' => CarbonImmutable::now()->toIso8601String()],
        );
        $orch->registerAndHeartbeat(
            $this->agentPayload('quarantined-agent'),
            ['status' => 'healthy', 'observed_at' => CarbonImmutable::now()->toIso8601String()],
        );
        (new AgentRuntimeRegistryQuarantineRepository)->quarantine('quarantined-agent', [
            'code' => 'operator_disabled',
            'declared_by' => 'operator-1',
        ]);

        $plan = $orch->planAssignment(['task_packet_id' => 'tp', 'required_capabilities' => ['code_edit'], 'dry_run_only' => true]);

        $this->assertSame('good-agent', $plan['selected_agent']);
        $this->assertNotSame('quarantined-agent', $plan['selected_agent']);
    }

    public function test_selected_agent_is_never_capability_mismatched(): void
    {
        $orch = new AgentRuntimeRegistryOrchestrator;
        $orch->registerAndHeartbeat(
            $this->agentPayload('mismatched-agent', ['capabilities' => ['research_only']]),
            ['status' => 'healthy', 'observed_at' => CarbonImmutable::now()->toIso8601String()],
        );

        $plan = $orch->planAssignment(['task_packet_id' => 'tp', 'required_capabilities' => ['code_edit'], 'dry_run_only' => true]);

        $this->assertSame('blocked', $plan['status']);
        $this->assertNull($plan['selected_agent']);
    }

    public function test_selected_agent_is_never_overloaded(): void
    {
        $orch = new AgentRuntimeRegistryOrchestrator;
        $orch->registerAndHeartbeat(
            $this->agentPayload('full-agent', ['max_parallel_tasks' => 1, 'current_task_count' => 1]),
            ['status' => 'healthy', 'observed_at' => CarbonImmutable::now()->toIso8601String()],
        );
        $orch->registerAndHeartbeat(
            $this->agentPayload('free-agent'),
            ['status' => 'healthy', 'observed_at' => CarbonImmutable::now()->toIso8601String()],
        );

        $plan = $orch->planAssignment(['task_packet_id' => 'tp', 'required_capabilities' => ['code_edit'], 'dry_run_only' => true]);

        $this->assertSame('free-agent', $plan['selected_agent']);
    }

    public function test_selected_agent_is_never_stale(): void
    {
        $orch = new AgentRuntimeRegistryOrchestrator;
        $orch->registerAndHeartbeat(
            $this->agentPayload('stale-agent'),
            ['status' => 'healthy', 'observed_at' => '2020-01-01T00:00:00+00:00'],
        );

        $plan = $orch->planAssignment(
            ['task_packet_id' => 'tp', 'required_capabilities' => ['code_edit'], 'dry_run_only' => true],
            ['ttl_seconds' => 90, 'reference_time' => '2020-01-01T01:00:00+00:00'],
        );

        $this->assertSame('blocked', $plan['status']);
        $this->assertNull($plan['selected_agent']);
    }
}
