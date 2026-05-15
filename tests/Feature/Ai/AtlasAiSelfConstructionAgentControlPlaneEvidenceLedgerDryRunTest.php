<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\AgentControlPlaneScopeLockPlanner;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneEvidenceLedgerDryRunTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_control_plane_evidence_ledger_dry_run.v1', AgentControlPlaneEvidenceLedgerDryRun::SCHEMA_VERSION);
        $this->assertSame('read_only_agent_control_plane_evidence_ledger_dry_run', AgentControlPlaneEvidenceLedgerDryRun::MODE);
    }

    public function test_planned_receipts_present(): void
    {
        $packet = $this->packet();
        $scope = (new AgentControlPlaneScopeLockPlanner)->plan($packet);
        $plan = (new AgentControlPlaneEvidenceLedgerDryRun)->plan($packet, $scope);
        $this->assertSame('planned', $plan['status']);
        $this->assertGreaterThanOrEqual(7, count($plan['planned_receipts']));
        $kinds = array_column($plan['planned_receipts'], 'receipt_kind');
        $this->assertContains('task_packet_created', $kinds);
        $this->assertContains('runtime_pilot_completed', $kinds);
    }

    public function test_no_ledger_write(): void
    {
        $packet = $this->packet();
        $scope = (new AgentControlPlaneScopeLockPlanner)->plan($packet);
        $plan = (new AgentControlPlaneEvidenceLedgerDryRun)->plan($packet, $scope);
        $this->assertFalse($plan['ledger_write_allowed']);
        $this->assertTrue($plan['dry_run_only']);
        $this->assertContains('evidence_ledger_dry_run_does_not_write_ledger', $plan['non_execution_guarantees']);
    }

    public function test_receipt_count_matches(): void
    {
        $packet = $this->packet();
        $scope = (new AgentControlPlaneScopeLockPlanner)->plan($packet);
        $plan = (new AgentControlPlaneEvidenceLedgerDryRun)->plan($packet, $scope);
        $this->assertSame(count($plan['planned_receipts']), (int) $plan['receipt_count']);
    }

    public function test_evidence_hash_stable(): void
    {
        $packet = $this->packet();
        $scope = (new AgentControlPlaneScopeLockPlanner)->plan($packet);
        $svc = new AgentControlPlaneEvidenceLedgerDryRun;
        $a = $svc->plan($packet, $scope);
        $b = $svc->plan($packet, $scope);
        $this->assertSame($a['evidence_hash'], $b['evidence_hash']);
        $this->assertSame($a['evidence_plan_hash'], $b['evidence_plan_hash']);
        $this->assertNotSame($a['evidence_plan_id'], $b['evidence_plan_id']);
    }

    public function test_additional_receipts_accepted(): void
    {
        $packet = $this->packet();
        $plan = (new AgentControlPlaneEvidenceLedgerDryRun)->plan($packet, [], [
            'additional_receipts' => ['custom_extra_receipt'],
        ]);
        $kinds = array_column($plan['planned_receipts'], 'receipt_kind');
        $this->assertContains('custom_extra_receipt', $kinds);
    }

    public function test_blocked_when_scope_blocked(): void
    {
        $packet = $this->packet();
        $plan = (new AgentControlPlaneEvidenceLedgerDryRun)->plan($packet, [
            'blocking_reasons' => ['unsafe_path_blocker'],
        ]);
        $this->assertSame('planned_blocked', $plan['status']);
        $this->assertContains('scope_lock_blocked', $plan['blocking_reasons']);
    }

    public function test_blocked_when_packet_blocked(): void
    {
        $packet = $this->packet();
        $packet['status'] = 'blocked';
        $plan = (new AgentControlPlaneEvidenceLedgerDryRun)->plan($packet, []);
        $this->assertSame('planned_blocked', $plan['status']);
        $this->assertContains('task_packet_not_planned', $plan['blocking_reasons']);
    }

    public function test_runtime_flags_false(): void
    {
        $packet = $this->packet();
        $plan = (new AgentControlPlaneEvidenceLedgerDryRun)->plan($packet, []);
        $this->assertFalse($plan['dispatch_allowed']);
        $this->assertFalse($plan['provider_call_allowed']);
        $this->assertFalse($plan['token_spend_allowed']);
        $this->assertFalse($plan['self_programming_allowed']);
        $this->assertFalse($plan['persistence_allowed']);
    }

    public function test_cli_status_returns_payload(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-evidence-ledger-dry-run-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.self_construction_agent_control_plane_evidence_ledger_dry_run_status.v1', $payload['schema_version']);
        $this->assertGreaterThanOrEqual(7, (int) data_get($payload, 'agent_control_plane_evidence_ledger_dry_run_status.receipt_count'));
    }

    public function test_cli_quartet_works(): void
    {
        foreach (['contract', 'preflight', 'implementation-packet'] as $stage) {
            Artisan::call('atlas:ai:self-construction', [
                "--agent-control-plane-evidence-ledger-dry-run-{$stage}" => true,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $stageKey = str_replace('-', '_', $stage);
            $this->assertSame(
                "atlas.self_construction_agent_control_plane_evidence_ledger_dry_run_{$stageKey}.v1",
                $payload['schema_version'],
            );
        }
    }

    public function test_required_receipts_constant(): void
    {
        $this->assertContains('task_packet_created', AgentControlPlaneEvidenceLedgerDryRun::REQUIRED_RECEIPTS);
        $this->assertContains('runtime_pilot_completed', AgentControlPlaneEvidenceLedgerDryRun::REQUIRED_RECEIPTS);
        $this->assertCount(7, AgentControlPlaneEvidenceLedgerDryRun::REQUIRED_RECEIPTS);
    }

    public function test_full_guarantee_set(): void
    {
        $plan = (new AgentControlPlaneEvidenceLedgerDryRun)->plan($this->packet(), []);
        foreach ([
            'evidence_ledger_dry_run_does_not_start_codex',
            'evidence_ledger_dry_run_does_not_call_codex_cli_or_app',
            'evidence_ledger_dry_run_does_not_spawn_subprocess',
            'evidence_ledger_dry_run_does_not_invoke_adapter',
            'evidence_ledger_dry_run_does_not_call_provider',
            'evidence_ledger_dry_run_does_not_dispatch_work',
            'evidence_ledger_dry_run_does_not_spend_tokens',
            'evidence_ledger_dry_run_does_not_enable_self_programming',
            'evidence_ledger_dry_run_does_not_write_ledger',
            'evidence_ledger_dry_run_does_not_persist_receipts',
            'evidence_ledger_dry_run_does_not_mutate_pointer',
        ] as $expected) {
            $this->assertContains($expected, $plan['non_execution_guarantees']);
        }
        foreach (AgentControlPlaneEvidenceLedgerDryRun::REQUIRED_RECEIPTS as $kind) {
            $this->assertIsString($kind);
            $this->assertNotEmpty($kind);
        }
    }

    public function test_payload_fully_shaped(): void
    {
        $packet = $this->packet();
        $plan = (new AgentControlPlaneEvidenceLedgerDryRun)->plan($packet, []);
        foreach ([
            'schema_version', 'mode', 'evidence_plan_id', 'task_packet_id', 'generated_at',
            'status', 'planned_receipts', 'planned_evidence_events', 'receipt_count',
            'event_count', 'evidence_hash', 'blocking_reasons', 'ledger_write_allowed',
            'dry_run_only', 'read_only', 'runtime_disabled', 'dispatch_allowed',
            'provider_call_allowed', 'token_spend_allowed', 'self_programming_allowed',
            'persistence_allowed', 'non_execution_guarantees', 'human_summary', 'evidence_plan_hash',
        ] as $key) {
            $this->assertArrayHasKey($key, $plan, "Missing $key");
        }
        foreach ($plan['planned_receipts'] as $r) {
            $this->assertArrayHasKey('receipt_kind', $r);
            $this->assertSame('planned', $r['receipt_status']);
            $this->assertFalse($r['persist_allowed']);
            $this->assertFalse($r['runtime_enabled']);
        }
        foreach ($plan['planned_evidence_events'] as $e) {
            $this->assertArrayHasKey('event_kind', $e);
            $this->assertFalse($e['persist_allowed']);
            $this->assertFalse($e['runtime_enabled']);
        }
        $this->assertContains('evidence_ledger_dry_run_does_not_persist_receipts', $plan['non_execution_guarantees']);
        $this->assertContains('evidence_ledger_dry_run_does_not_write_ledger', $plan['non_execution_guarantees']);
        $this->assertContains('evidence_ledger_dry_run_does_not_dispatch_work', $plan['non_execution_guarantees']);
        $this->assertContains('evidence_ledger_dry_run_does_not_call_provider', $plan['non_execution_guarantees']);
        $this->assertContains('evidence_ledger_dry_run_does_not_start_codex', $plan['non_execution_guarantees']);
    }

    /**
     * @return array<string, mixed>
     */
    private function packet(): array
    {
        return (new AgentControlPlaneTaskPacketBuilder)->build([
            'objective' => 'evidence dry run',
            'operator_id' => 'tester',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/Foo.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/Foo.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['x'],
        ]);
    }
}
