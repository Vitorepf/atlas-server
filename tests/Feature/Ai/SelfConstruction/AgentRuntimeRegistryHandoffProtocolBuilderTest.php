<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentRuntimeRegistryHandoffProtocolBuilder;
use Tests\TestCase;

final class AgentRuntimeRegistryHandoffProtocolBuilderTest extends TestCase
{
    public function test_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_runtime_registry_handoff_protocol.v1', AgentRuntimeRegistryHandoffProtocolBuilder::SCHEMA_VERSION);
        $this->assertSame('read_only_agent_runtime_registry_handoff_protocol', AgentRuntimeRegistryHandoffProtocolBuilder::MODE);
    }

    public function test_valid_handoff_plan(): void
    {
        $builder = new AgentRuntimeRegistryHandoffProtocolBuilder;
        $plan = $builder->build(
            $this->fromAgent(),
            $this->toAgent(),
            $this->task(),
            ['continuation_summary_hash' => str_repeat('a', 64)],
        );
        $this->assertSame('planned', $plan['status']);
        $this->assertSame('from', $plan['from_agent_id']);
        $this->assertSame('to', $plan['to_agent_id']);
        $this->assertSame([], $plan['blockers']);
        $this->assertFalse($plan['handoff_execution_allowed']);
        $this->assertFalse($plan['runtime_execution_allowed']);
        $this->assertFalse($plan['dispatch_allowed']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $plan['handoff_hash']);
    }

    public function test_same_agent_warning(): void
    {
        $builder = new AgentRuntimeRegistryHandoffProtocolBuilder;
        $plan = $builder->build(
            ['agent_id' => 'same'],
            $this->toAgent(['agent_id' => 'same']),
            $this->task(),
            ['continuation_summary_hash' => str_repeat('a', 64)],
        );
        $this->assertContains('handoff_to_same_agent', $plan['warnings']);
    }

    public function test_missing_capability_blocked(): void
    {
        $builder = new AgentRuntimeRegistryHandoffProtocolBuilder;
        $plan = $builder->build(
            $this->fromAgent(),
            $this->toAgent(['capabilities' => ['docs_writer']]),
            $this->task(),
            ['continuation_summary_hash' => str_repeat('a', 64)],
        );
        $this->assertContains('to_agent_missing_capabilities', $plan['blockers']);
        $this->assertSame('blocked', $plan['status']);
    }

    public function test_missing_continuation_summary_blocked(): void
    {
        $builder = new AgentRuntimeRegistryHandoffProtocolBuilder;
        $plan = $builder->build(
            $this->fromAgent(),
            $this->toAgent(),
            $this->task(),
        );
        $this->assertContains('missing_continuation_summary', $plan['blockers']);
        $this->assertSame('blocked', $plan['status']);
    }

    public function test_missing_evidence_refs_blocked_when_required(): void
    {
        $builder = new AgentRuntimeRegistryHandoffProtocolBuilder;
        $task = $this->task();
        $task['evidence_refs'] = [];
        $plan = $builder->build(
            $this->fromAgent(),
            $this->toAgent(),
            $task,
            ['continuation_summary_hash' => str_repeat('a', 64)],
        );
        $this->assertContains('missing_evidence_refs', $plan['blockers']);
    }

    public function test_operator_approval_required_for_high_risk(): void
    {
        $builder = new AgentRuntimeRegistryHandoffProtocolBuilder;
        $task = $this->task(['risk_level' => 'high']);
        $plan = $builder->build(
            $this->fromAgent(),
            $this->toAgent(),
            $task,
            ['continuation_summary_hash' => str_repeat('a', 64)],
        );
        $this->assertTrue($plan['operator_approval_required']);
    }

    public function test_to_agent_quarantined_blocked(): void
    {
        $builder = new AgentRuntimeRegistryHandoffProtocolBuilder;
        $plan = $builder->build(
            $this->fromAgent(),
            $this->toAgent(['status' => 'quarantined']),
            $this->task(),
            ['continuation_summary_hash' => str_repeat('a', 64)],
        );
        $this->assertContains('to_agent_quarantined', $plan['blockers']);
    }

    public function test_lease_support_required(): void
    {
        $builder = new AgentRuntimeRegistryHandoffProtocolBuilder;
        $task = $this->task(['requires_lease' => true]);
        $plan = $builder->build(
            $this->fromAgent(),
            $this->toAgent(['lease_supported' => false]),
            $task,
            ['continuation_summary_hash' => str_repeat('a', 64)],
        );
        $this->assertContains('to_agent_lease_support_missing', $plan['blockers']);
    }

    public function test_workspace_required(): void
    {
        $builder = new AgentRuntimeRegistryHandoffProtocolBuilder;
        $task = $this->task(['workspace_policy' => 'isolated']);
        $plan = $builder->build(
            $this->fromAgent(),
            $this->toAgent(['workspace_isolation_supported' => false]),
            $task,
            ['continuation_summary_hash' => str_repeat('a', 64)],
        );
        $this->assertContains('to_agent_workspace_isolation_missing', $plan['blockers']);
    }

    public function test_handoff_execution_false(): void
    {
        $builder = new AgentRuntimeRegistryHandoffProtocolBuilder;
        $plan = $builder->build(
            $this->fromAgent(),
            $this->toAgent(),
            $this->task(),
            ['continuation_summary_hash' => str_repeat('a', 64)],
        );
        $this->assertFalse($plan['handoff_execution_allowed']);
        $this->assertFalse($plan['runtime_execution_allowed']);
        $this->assertFalse($plan['dispatch_allowed']);
        $this->assertFalse($plan['provider_call_allowed']);
        $this->assertFalse($plan['token_spend_allowed']);
        $this->assertFalse($plan['self_programming_allowed']);
        $this->assertFalse($plan['ledger_write_allowed']);
    }

    public function test_runtime_flags_helper(): void
    {
        $builder = new AgentRuntimeRegistryHandoffProtocolBuilder;
        foreach ($builder->runtimeFlags() as $key => $value) {
            $this->assertFalse($value, "flag {$key} must remain false");
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function fromAgent(array $overrides = []): array
    {
        return array_merge([
            'agent_id' => 'from',
            'status' => 'available',
            'capabilities' => ['code_edit', 'evidence_collection'],
            'lease_supported' => true,
            'workspace_isolation_supported' => true,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function toAgent(array $overrides = []): array
    {
        return array_merge([
            'agent_id' => 'to',
            'status' => 'available',
            'capabilities' => ['code_edit', 'evidence_collection'],
            'lease_supported' => true,
            'workspace_isolation_supported' => true,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function task(array $overrides = []): array
    {
        return array_merge([
            'task_packet_id' => 'tp-1',
            'task_packet_hash' => str_repeat('b', 64),
            'required_capabilities' => ['code_edit', 'evidence_collection'],
            'risk_level' => 'low',
            'evidence_refs' => ['ev/path/1.md'],
            'workspace_policy' => 'none',
            'requires_lease' => false,
            'evidence_required' => true,
        ], $overrides);
    }
}
