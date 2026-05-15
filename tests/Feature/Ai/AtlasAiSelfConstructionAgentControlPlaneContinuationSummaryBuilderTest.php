<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\AgentControlPlaneScopeLockPlanner;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneContinuationSummaryBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_control_plane_continuation_summary.v1', AgentControlPlaneContinuationSummaryBuilder::SCHEMA_VERSION);
        $this->assertSame('read_only_agent_control_plane_continuation_summary_builder', AgentControlPlaneContinuationSummaryBuilder::MODE);
    }

    public function test_summary_generated(): void
    {
        $packet = $this->packet();
        $scope = (new AgentControlPlaneScopeLockPlanner)->plan($packet);
        $evidence = (new AgentControlPlaneEvidenceLedgerDryRun)->plan($packet, $scope);
        $summary = (new AgentControlPlaneContinuationSummaryBuilder)->build($packet, $evidence);
        $this->assertSame('planned', $summary['status']);
        $this->assertNotEmpty($summary['continuation_hash']);
        $this->assertSame(AgentControlPlaneContinuationSummaryBuilder::SCHEMA_VERSION, $summary['schema_version']);
    }

    public function test_resume_instructions_present(): void
    {
        $packet = $this->packet();
        $summary = (new AgentControlPlaneContinuationSummaryBuilder)->build($packet);
        $this->assertTrue($summary['resume_instructions']['do_not_dispatch_provider']);
        $this->assertTrue($summary['resume_instructions']['do_not_write_ledger']);
        $this->assertFalse($summary['resume_instructions']['continuation_runtime_enabled']);
    }

    public function test_context_compaction_present(): void
    {
        $packet = $this->packet();
        $evidence = (new AgentControlPlaneEvidenceLedgerDryRun)->plan($packet, []);
        $summary = (new AgentControlPlaneContinuationSummaryBuilder)->build($packet, $evidence);
        $this->assertSame('pinned_packet_hash_plus_compacted_log', $summary['context_compaction_plan']['strategy']);
        $this->assertNotEmpty($summary['context_compaction_plan']['pinned']['task_packet_hash']);
        $this->assertFalse($summary['context_compaction_plan']['runtime_enabled']);
    }

    public function test_hash_stable(): void
    {
        $packet = $this->packet();
        $svc = new AgentControlPlaneContinuationSummaryBuilder;
        $a = $svc->build($packet);
        $b = $svc->build($packet);
        $this->assertSame($a['continuation_hash'], $b['continuation_hash']);
        $this->assertNotSame($a['continuation_summary_id'], $b['continuation_summary_id']);
    }

    public function test_default_next_actions(): void
    {
        $packet = $this->packet();
        $summary = (new AgentControlPlaneContinuationSummaryBuilder)->build($packet);
        $this->assertContains('reload_task_packet', $summary['next_actions']);
        $this->assertContains('verify_scope_lock_plan', $summary['next_actions']);
    }

    public function test_blockers_propagated_and_merged(): void
    {
        $packet = $this->packet();
        $packet['blocking_reasons'] = ['x', 'y'];
        $summary = (new AgentControlPlaneContinuationSummaryBuilder)->build($packet, [], [
            'blockers' => ['z'],
        ]);
        $this->assertSame('planned_blocked', $summary['status']);
        foreach (['x', 'y', 'z'] as $reason) {
            $this->assertContains($reason, $summary['blockers']);
        }
    }

    public function test_runtime_flags_false(): void
    {
        $packet = $this->packet();
        $summary = (new AgentControlPlaneContinuationSummaryBuilder)->build($packet);
        $this->assertFalse($summary['dispatch_allowed']);
        $this->assertFalse($summary['provider_call_allowed']);
        $this->assertFalse($summary['ledger_write_allowed']);
        $this->assertFalse($summary['persistence_allowed']);
    }

    public function test_cli_status_returns_payload(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-continuation-summary-builder-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.self_construction_agent_control_plane_continuation_summary_builder_status.v1', $payload['schema_version']);
        $this->assertNotEmpty(data_get($payload, 'agent_control_plane_continuation_summary_builder_status.continuation_hash'));
    }

    public function test_cli_quartet_works(): void
    {
        foreach (['contract', 'preflight', 'implementation-packet'] as $stage) {
            Artisan::call('atlas:ai:self-construction', [
                "--agent-control-plane-continuation-summary-builder-{$stage}" => true,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $stageKey = str_replace('-', '_', $stage);
            $this->assertSame(
                "atlas.self_construction_agent_control_plane_continuation_summary_builder_{$stageKey}.v1",
                $payload['schema_version'],
            );
        }
    }

    public function test_evidence_refs_propagated(): void
    {
        $packet = $this->packet();
        $evidence = (new AgentControlPlaneEvidenceLedgerDryRun)->plan($packet, []);
        $summary = (new AgentControlPlaneContinuationSummaryBuilder)->build($packet, $evidence);
        $this->assertSame((string) $evidence['evidence_plan_hash'], $summary['evidence_refs']['evidence_plan_hash']);
        $this->assertSame((string) $evidence['evidence_hash'], $summary['evidence_refs']['evidence_hash']);
    }

    public function test_full_guarantee_set(): void
    {
        $summary = (new AgentControlPlaneContinuationSummaryBuilder)->build($this->packet());
        foreach ([
            'continuation_summary_builder_does_not_start_codex',
            'continuation_summary_builder_does_not_call_codex_cli_or_app',
            'continuation_summary_builder_does_not_spawn_subprocess',
            'continuation_summary_builder_does_not_invoke_adapter',
            'continuation_summary_builder_does_not_call_provider',
            'continuation_summary_builder_does_not_dispatch_work',
            'continuation_summary_builder_does_not_spend_tokens',
            'continuation_summary_builder_does_not_enable_self_programming',
            'continuation_summary_builder_does_not_write_ledger',
            'continuation_summary_builder_does_not_persist_context',
            'continuation_summary_builder_does_not_mutate_pointer',
        ] as $expected) {
            $this->assertContains($expected, $summary['non_execution_guarantees']);
        }
    }

    public function test_payload_fully_shaped(): void
    {
        $packet = $this->packet();
        $summary = (new AgentControlPlaneContinuationSummaryBuilder)->build($packet);
        foreach ([
            'schema_version', 'mode', 'continuation_summary_id', 'task_packet_id', 'generated_at',
            'status', 'objective_summary', 'scope_summary', 'current_state', 'next_actions',
            'blockers', 'evidence_refs', 'context_compaction_plan', 'resume_instructions',
            'read_only', 'runtime_disabled', 'dispatch_allowed', 'provider_call_allowed',
            'token_spend_allowed', 'self_programming_allowed', 'ledger_write_allowed',
            'persistence_allowed', 'non_execution_guarantees', 'human_summary', 'continuation_hash',
        ] as $key) {
            $this->assertArrayHasKey($key, $summary, "Missing $key");
        }
        $this->assertTrue($summary['resume_instructions']['verify_task_packet_hash_matches']);
        $this->assertTrue($summary['resume_instructions']['reload_acceptance_criteria']);
        $this->assertTrue($summary['resume_instructions']['reload_scope_lock_plan']);
        $this->assertTrue($summary['resume_instructions']['reload_evidence_dry_run']);
        $this->assertContains('continuation_summary_builder_does_not_persist_context', $summary['non_execution_guarantees']);
        $this->assertContains('continuation_summary_builder_does_not_dispatch_work', $summary['non_execution_guarantees']);
        $this->assertContains('continuation_summary_builder_does_not_call_provider', $summary['non_execution_guarantees']);
        $this->assertContains('continuation_summary_builder_does_not_start_codex', $summary['non_execution_guarantees']);
        $this->assertContains('continuation_summary_builder_does_not_mutate_pointer', $summary['non_execution_guarantees']);
        $this->assertArrayHasKey('task_packet_hash', $summary['context_compaction_plan']['pinned']);
        $this->assertArrayHasKey('scope_hash', $summary['context_compaction_plan']['pinned']);
        $this->assertArrayHasKey('acceptance_hash', $summary['context_compaction_plan']['pinned']);
        $this->assertArrayHasKey('evidence_hash', $summary['context_compaction_plan']['pinned']);
    }

    /**
     * @return array<string, mixed>
     */
    private function packet(): array
    {
        return (new AgentControlPlaneTaskPacketBuilder)->build([
            'objective' => 'continuation test',
            'operator_id' => 'tester',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/Foo.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/Foo.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['x'],
        ]);
    }
}
