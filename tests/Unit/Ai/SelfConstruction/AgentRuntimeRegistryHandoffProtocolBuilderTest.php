<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentRuntimeRegistryHandoffProtocolBuilder;
use Tests\TestCase;

final class AgentRuntimeRegistryHandoffProtocolBuilderTest extends TestCase
{
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
            'lease_id' => 'lease-1',
            'allowed_files' => ['app/Foo.php', 'tests/FooTest.php'],
            'runnable_gates' => ['regression_test', 'code_index'],
            'required_capabilities' => ['code_edit', 'evidence_collection'],
            'risk_level' => 'low',
            'evidence_refs' => ['ev/path/1.md'],
            'workspace_policy' => 'none',
            'requires_lease' => false,
            'evidence_required' => true,
        ], $overrides);
    }

    private function svc(): AgentRuntimeRegistryHandoffProtocolBuilder
    {
        return new AgentRuntimeRegistryHandoffProtocolBuilder;
    }

    // ── AC2: from/to agent ids, task id, lease id, allowed_files, runnable gates, required evidence ──

    public function test_plan_includes_lease_id_allowed_files_runnable_gates_and_required_evidence(): void
    {
        $plan = $this->svc()->build(
            $this->fromAgent(),
            $this->toAgent(),
            $this->task(),
            ['continuation_summary_hash' => str_repeat('a', 64)],
        );

        $this->assertSame('from', $plan['from_agent_id']);
        $this->assertSame('to', $plan['to_agent_id']);
        $this->assertSame('tp-1', $plan['task_packet_id']);
        $this->assertSame('lease-1', $plan['lease_id']);
        $this->assertSame(['app/Foo.php', 'tests/FooTest.php'], $plan['allowed_files']);
        $this->assertSame(['code_index', 'regression_test'], $plan['runnable_gates']);
        $this->assertSame(['ev/path/1.md'], $plan['required_evidence_refs']);
        $this->assertSame('planned', $plan['status']);
    }

    public function test_lease_id_and_allowed_files_default_empty_when_absent(): void
    {
        $task = $this->task();
        unset($task['lease_id'], $task['allowed_files'], $task['runnable_gates']);

        $plan = $this->svc()->build(
            $this->fromAgent(),
            $this->toAgent(),
            $task,
            ['continuation_summary_hash' => str_repeat('a', 64)],
        );

        $this->assertSame('', $plan['lease_id']);
        $this->assertSame([], $plan['allowed_files']);
        $this->assertSame([], $plan['runnable_gates']);
    }

    // ── AC3: blocks on capability, freshness or proof continuity from EITHER side ──

    public function test_blocks_when_from_agent_lacks_required_capability(): void
    {
        $plan = $this->svc()->build(
            $this->fromAgent(['capabilities' => ['docs_writer']]),
            $this->toAgent(),
            $this->task(),
            ['continuation_summary_hash' => str_repeat('a', 64)],
        );

        $this->assertContains('from_agent_missing_capabilities', $plan['blockers']);
        $this->assertSame('blocked', $plan['status']);
    }

    public function test_blocks_when_to_agent_lacks_required_capability(): void
    {
        $plan = $this->svc()->build(
            $this->fromAgent(),
            $this->toAgent(['capabilities' => ['docs_writer']]),
            $this->task(),
            ['continuation_summary_hash' => str_repeat('a', 64)],
        );

        $this->assertContains('to_agent_missing_capabilities', $plan['blockers']);
    }

    public function test_from_capability_match_reported_alongside_to_capability_match(): void
    {
        $plan = $this->svc()->build(
            $this->fromAgent(),
            $this->toAgent(),
            $this->task(),
            ['continuation_summary_hash' => str_repeat('a', 64)],
        );

        $this->assertArrayHasKey('from_capability_match', $plan);
        $this->assertArrayHasKey('capability_match', $plan);
        $this->assertSame('matched', $plan['from_capability_match']['match_status']);
    }

    public function test_blocks_when_evidence_is_stale_at_handoff_time(): void
    {
        $task = $this->task(['evidence_age_seconds' => AgentRuntimeRegistryHandoffProtocolBuilder::FRESHNESS_STALE_AFTER_SECONDS + 1]);

        $plan = $this->svc()->build(
            $this->fromAgent(),
            $this->toAgent(),
            $task,
            ['continuation_summary_hash' => str_repeat('a', 64)],
        );

        $this->assertContains('stale_evidence_at_handoff', $plan['blockers']);
        $this->assertSame('blocked', $plan['status']);
    }

    public function test_fresh_evidence_within_threshold_does_not_block(): void
    {
        $task = $this->task(['evidence_age_seconds' => AgentRuntimeRegistryHandoffProtocolBuilder::FRESHNESS_STALE_AFTER_SECONDS]);

        $plan = $this->svc()->build(
            $this->fromAgent(),
            $this->toAgent(),
            $task,
            ['continuation_summary_hash' => str_repeat('a', 64)],
        );

        $this->assertNotContains('stale_evidence_at_handoff', $plan['blockers']);
        $this->assertSame('planned', $plan['status']);
    }

    public function test_blocks_when_proof_continuity_missing(): void
    {
        $plan = $this->svc()->build(
            $this->fromAgent(),
            $this->toAgent(),
            $this->task(),
        );

        $this->assertContains('missing_continuation_summary', $plan['blockers']);
        $this->assertSame('blocked', $plan['status']);
    }

    public function test_blocks_when_required_lease_id_missing(): void
    {
        $task = $this->task(['requires_lease' => true, 'lease_id' => '']);

        $plan = $this->svc()->build(
            $this->fromAgent(),
            $this->toAgent(),
            $task,
            ['continuation_summary_hash' => str_repeat('a', 64)],
        );

        $this->assertContains('missing_lease_id_for_required_lease', $plan['blockers']);
    }

    public function test_does_not_block_on_lease_id_when_lease_not_required(): void
    {
        $task = $this->task(['requires_lease' => false, 'lease_id' => '']);

        $plan = $this->svc()->build(
            $this->fromAgent(),
            $this->toAgent(),
            $task,
            ['continuation_summary_hash' => str_repeat('a', 64)],
        );

        $this->assertNotContains('missing_lease_id_for_required_lease', $plan['blockers']);
    }

    // ── AC4: rollback and give_back instructions always present ────────────────

    public function test_plan_always_includes_rollback_and_give_back_instructions(): void
    {
        $planned = $this->svc()->build(
            $this->fromAgent(),
            $this->toAgent(),
            $this->task(),
            ['continuation_summary_hash' => str_repeat('a', 64)],
        );
        $this->assertNotSame('', $planned['rollback_instructions']);
        $this->assertNotSame('', $planned['give_back_instructions']);
        $this->assertStringContainsString('tp-1', $planned['rollback_instructions']);
        $this->assertStringContainsString('lease-1', $planned['rollback_instructions']);

        $blocked = $this->svc()->build($this->fromAgent(), $this->toAgent(), $this->task());
        $this->assertSame('blocked', $blocked['status']);
        $this->assertNotSame('', $blocked['rollback_instructions']);
        $this->assertStringContainsString('missing_continuation_summary', $blocked['give_back_instructions']);
    }

    public function test_give_back_instructions_name_none_when_no_blockers(): void
    {
        $plan = $this->svc()->build(
            $this->fromAgent(),
            $this->toAgent(),
            $this->task(),
            ['continuation_summary_hash' => str_repeat('a', 64)],
        );

        $this->assertStringContainsString('reason=handoff_blocked:none', $plan['give_back_instructions']);
    }

    // ── determinism ──────────────────────────────────────────────────────────

    public function test_handoff_hash_is_deterministic_for_identical_input(): void
    {
        $options = ['handoff_protocol_id' => 'fixed-id', 'continuation_summary_hash' => str_repeat('a', 64)];

        $a = $this->svc()->build($this->fromAgent(), $this->toAgent(), $this->task(), $options);
        $b = $this->svc()->build($this->fromAgent(), $this->toAgent(), $this->task(), $options);

        $this->assertSame($a['handoff_hash'], $b['handoff_hash']);
    }

    // ── AC: handoff output includes proof_contract with task_state, allowed_files_hash, required_evidence and rollback_expectation ──

    public function test_handoff_includes_proof_contract(): void
    {
        $plan = $this->svc()->build(
            $this->fromAgent(),
            $this->toAgent(),
            $this->task(),
            ['continuation_summary_hash' => str_repeat('a', 64)],
        );

        $this->assertArrayHasKey('proof_contract', $plan);
        $contract = $plan['proof_contract'];

        $this->assertArrayHasKey('task_state', $contract);
        $this->assertArrayHasKey('allowed_files_hash', $contract);
        $this->assertArrayHasKey('required_evidence', $contract);
        $this->assertArrayHasKey('rollback_expectation', $contract);

        $this->assertSame('tp-1', $contract['task_state']['task_packet_id']);
        $this->assertNotEmpty($contract['allowed_files_hash']);
        $this->assertSame(['ev/path/1.md'], $contract['required_evidence']);
        $this->assertNotEmpty($contract['rollback_expectation']);
    }

    // ── AC: handoff is blocked when source worker lacks concrete progress or evidence state ──

    public function test_handoff_blocked_when_source_lacks_evidence(): void
    {
        $task = $this->task(['evidence_refs' => []]);

        $plan = $this->svc()->build(
            $this->fromAgent(),
            $this->toAgent(),
            $task,
            ['continuation_summary_hash' => str_repeat('a', 64)],
        );

        $this->assertSame('blocked', $plan['status']);
        $this->assertContains('missing_evidence_refs', $plan['blockers']);
    }

    public function test_handoff_blocked_when_source_lacks_continuation_summary(): void
    {
        $plan = $this->svc()->build(
            $this->fromAgent(),
            $this->toAgent(),
            $this->task(),
        );

        $this->assertSame('blocked', $plan['status']);
        $this->assertContains('missing_continuation_summary', $plan['blockers']);
    }

    // ── AC: provider-safe summaries exclude raw prompts, traces and secret-like metadata ──

    public function test_handoff_output_excludes_raw_prompts_and_traces(): void
    {
        $plan = $this->svc()->build(
            $this->fromAgent(),
            $this->toAgent(),
            $this->task(),
            ['continuation_summary_hash' => str_repeat('a', 64)],
        );

        $json = (string) json_encode($plan);

        $this->assertStringNotContainsString('raw_prompt', $json);
        $this->assertStringNotContainsString('provider_trace', $json);
        $this->assertStringNotContainsString('secret', $json);
        $this->assertStringNotContainsString('api_key', $json);
    }
}
