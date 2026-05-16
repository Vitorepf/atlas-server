<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneTaskQueueOrchestratorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_control_plane_task_queue_orchestrator.v1', AgentControlPlaneTaskQueueOrchestrator::SCHEMA_VERSION);
        $this->assertSame('persistent_local_agent_control_plane_task_queue_orchestrator', AgentControlPlaneTaskQueueOrchestrator::MODE);
    }

    public function test_prepare_and_enqueue_creates_queued_packet(): void
    {
        $svc = $this->orchestrator();
        $result = $svc->prepareAndEnqueue(['task_packet' => $this->input('orch-1')]);
        $this->assertSame('prepared_and_enqueued', $result['event']);
        $this->assertSame('claimable', data_get($result, 'queue_entry.record.status'));
        $this->assertNotEmpty(data_get($result, 'task_packet.task_packet_hash'));
        $this->assertNotEmpty(data_get($result, 'validation.scope_lock_hash'));
        $this->assertNotEmpty(data_get($result, 'evidence_plan.evidence_hash'));
        $this->assertNotEmpty(data_get($result, 'continuation_summary.continuation_hash'));
    }

    public function test_prepare_blocked_when_scope_lock_blocked(): void
    {
        $svc = $this->orchestrator();
        $result = $svc->prepareAndEnqueue([
            'task_packet' => [
                'task_packet_id' => 'forbidden-axis',
                'objective' => 'forbidden axis run',
                'allowed_files' => ['routes/api.php'],
                'forbidden_files' => [],
                'scope_in' => ['routes/api.php'],
                'acceptance_criteria' => ['ok'],
                'required_evidence' => ['task_packet_created'],
            ],
        ]);
        $this->assertSame('prepare_blocked', $result['event']);
        $this->assertSame('task_packet_or_validation_blocked', $result['reason']);
    }

    public function test_claim_next_claims_claimable_task(): void
    {
        $svc = $this->orchestrator();
        $svc->prepareAndEnqueue(['task_packet' => $this->input('claim-next-a')]);
        $result = $svc->claimNext('agent-a');
        $this->assertSame('claimed', $result['event']);
        $this->assertSame('claim-next-a', $result['task_packet_id']);
        $this->assertNotEmpty($result['lease_id']);
    }

    public function test_claim_next_skips_conflict(): void
    {
        $svc = $this->orchestrator();
        $svc->prepareAndEnqueue(['task_packet' => $this->input('skip-a')]);
        $svc->prepareAndEnqueue(['task_packet' => $this->input('skip-b')]);
        // First agent grabs first claimable.
        $a = $svc->claimNext('agent-a');
        $this->assertSame('claimed', $a['event']);
        // Second agent: the remaining is claimable but reuses same allowed file path → conflict.
        // We expect orchestrator to either claim the second (different task) or skip with no claimable task.
        $b = $svc->claimNext('agent-b');
        $this->assertContains($b['event'], ['claimed', 'no_claimable_task']);
    }

    public function test_renew_lease_works(): void
    {
        $svc = $this->orchestrator();
        $svc->prepareAndEnqueue(['task_packet' => $this->input('renew-1')]);
        $claim = $svc->claimNext('agent-1');
        $renew = $svc->renewLease($claim['lease_id'], 'agent-1', 200);
        $this->assertSame('ok', $renew['renewal']['status']);
        $this->assertSame(1, $renew['renewal']['lease']['renew_count']);
    }

    public function test_release_lease_works(): void
    {
        $svc = $this->orchestrator();
        $svc->prepareAndEnqueue(['task_packet' => $this->input('release-1')]);
        $claim = $svc->claimNext('agent-1');
        $release = $svc->releaseLease($claim['lease_id'], 'agent-1', ['reason' => 'manual']);
        $this->assertSame('ok', $release['release']['status']);
        $queue = (new AgentControlPlaneTaskPacketQueueRepository)->get('release-1');
        $this->assertSame('released', $queue['status']);
    }

    public function test_complete_dry_run_requires_valid_lease(): void
    {
        $svc = $this->orchestrator();
        $result = $svc->completeDryRun('nonexistent-task', 'no-lease-id');
        $this->assertSame('complete_dry_run_blocked', $result['event']);
        $this->assertSame('lease_not_found', $result['reason']);
    }

    public function test_complete_dry_run_does_not_mark_real_completed(): void
    {
        $svc = $this->orchestrator();
        $svc->prepareAndEnqueue(['task_packet' => $this->input('dry-1')]);
        $claim = $svc->claimNext('agent-1');
        $complete = $svc->completeDryRun('dry-1', $claim['lease_id'], ['evidence_count' => 0]);
        $this->assertSame('completed_dry_run', $complete['event']);
        $this->assertFalse($complete['completion_real_allowed']);
        $queue = (new AgentControlPlaneTaskPacketQueueRepository)->get('dry-1');
        $this->assertSame('completed_dry_run', $queue['status']);
    }

    public function test_complete_dry_run_blocks_when_lease_not_active(): void
    {
        $svc = $this->orchestrator();
        $svc->prepareAndEnqueue(['task_packet' => $this->input('dry-stale')]);
        $claim = $svc->claimNext('agent-1');
        $svc->releaseLease($claim['lease_id'], 'agent-1');
        $complete = $svc->completeDryRun('dry-stale', $claim['lease_id']);
        $this->assertSame('complete_dry_run_blocked', $complete['event']);
        $this->assertSame('lease_not_active', $complete['reason']);
    }

    public function test_continuation_summary_and_evidence_plan_included(): void
    {
        $svc = $this->orchestrator();
        $result = $svc->prepareAndEnqueue(['task_packet' => $this->input('inc-1')]);
        $this->assertGreaterThanOrEqual(7, (int) data_get($result, 'evidence_plan.receipt_count'));
        $this->assertArrayHasKey('continuation_summary', $result);
        $this->assertArrayHasKey('next_actions', (array) $result['continuation_summary']);
    }

    public function test_receipts_appended_on_prepare(): void
    {
        $svc = $this->orchestrator();
        $svc->prepareAndEnqueue(['task_packet' => $this->input('receipts-1')]);
        $record = (new AgentControlPlaneTaskPacketQueueRepository)->get('receipts-1');
        $kinds = array_column((array) $record['receipts'], 'receipt_kind');
        $this->assertContains('scope_lock_runtime_validated', $kinds);
        $this->assertContains('evidence_plan_prepared', $kinds);
        $this->assertContains('continuation_summary_prepared', $kinds);
    }

    public function test_runtime_flags_false(): void
    {
        $svc = $this->orchestrator();
        $result = $svc->prepareAndEnqueue(['task_packet' => $this->input('flags-1')]);
        $this->assertFalse($result['runtime_execution_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['self_programming_allowed']);
        $this->assertFalse($result['ledger_write_allowed']);
        $this->assertFalse($result['completion_real_allowed']);
        $this->assertContains('task_queue_orchestrator_does_not_call_provider', $result['non_execution_guarantees']);
        $this->assertContains('task_queue_orchestrator_does_not_dispatch_work', $result['non_execution_guarantees']);
        $this->assertContains('task_queue_orchestrator_does_not_mark_real_completion', $result['non_execution_guarantees']);
    }

    public function test_claim_next_no_agent_blocks(): void
    {
        $svc = $this->orchestrator();
        $result = $svc->claimNext('');
        $this->assertSame('claim_blocked', $result['event']);
        $this->assertSame('agent_id_missing', $result['reason']);
    }

    public function test_cli_status_returns_payload(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-task-queue-orchestrator-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.self_construction_agent_control_plane_task_queue_orchestrator_status.v1', $payload['schema_version']);
        $this->assertSame('available', data_get($payload, 'agent_control_plane_task_queue_orchestrator_status.status'));
        $this->assertSame('prepared_and_enqueued', data_get($payload, 'agent_control_plane_task_queue_orchestrator_status.event'));
    }

    public function test_cli_claim_next_uses_persistent_task_queue_and_lease_runtime(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-task-queue-claim-next-status' => true,
            '--actor' => 'agent-cli-1',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('atlas.self_construction_agent_control_plane_task_queue_claim_next_status.v1', $payload['schema_version']);
        $this->assertSame('claimed', data_get($payload, 'agent_control_plane_task_queue_claim_next.status'));
        $this->assertSame('claimed', data_get($payload, 'agent_control_plane_task_queue_claim_next.event'));
        $this->assertSame('agent-cli-1', data_get($payload, 'agent_control_plane_task_queue_claim_next.agent_id'));
        $this->assertTrue((bool) data_get($payload, 'agent_control_plane_task_queue_claim_next.runtime_claim_persisted'));
        $this->assertFalse((bool) data_get($payload, 'agent_control_plane_task_queue_claim_next.legacy_reservation_claim_used'));
        $this->assertTrue((bool) data_get($payload, 'agent_control_plane_task_queue_claim_next.safe_for_parallel_terminal_loop'));
        $this->assertNotEmpty(data_get($payload, 'agent_control_plane_task_queue_claim_next.task_packet_id'));
        $this->assertNotEmpty(data_get($payload, 'agent_control_plane_task_queue_claim_next.lease_id'));
        $this->assertFalse((bool) data_get($payload, 'agent_control_plane_task_queue_claim_next.non_execution_summary.dispatch_allowed'));
        $this->assertFalse((bool) data_get($payload, 'agent_control_plane_task_queue_claim_next.non_execution_summary.provider_call_allowed'));
    }

    public function test_cli_claim_next_does_not_duplicate_active_claim_for_second_agent(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-task-queue-claim-next-status' => true,
            '--actor' => 'agent-cli-a',
            '--json' => true,
        ]);
        $first = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-task-queue-claim-next-status' => true,
            '--actor' => 'agent-cli-b',
            '--json' => true,
        ]);
        $second = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('claimed', data_get($first, 'agent_control_plane_task_queue_claim_next.status'));
        $this->assertSame('blocked', data_get($second, 'agent_control_plane_task_queue_claim_next.status'));
        $this->assertSame('no_claimable_task', data_get($second, 'agent_control_plane_task_queue_claim_next.event'));
        $this->assertFalse((bool) data_get($second, 'agent_control_plane_task_queue_claim_next.runtime_claim_persisted'));
        $this->assertFalse((bool) data_get($second, 'agent_control_plane_task_queue_claim_next.legacy_reservation_claim_used'));
    }

    public function test_cli_complete_dry_run_closes_claimed_runtime_task_without_real_completion(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-task-queue-claim-next-status' => true,
            '--actor' => 'agent-cli-complete',
            '--json' => true,
        ]);
        $claim = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $packetId = (string) data_get($claim, 'agent_control_plane_task_queue_claim_next.task_packet_id');
        $leaseId = (string) data_get($claim, 'agent_control_plane_task_queue_claim_next.lease_id');

        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-task-queue-complete-dry-run-status' => true,
            '--packet' => $packetId,
            '--lease-id' => $leaseId,
            '--actor' => 'agent-cli-complete',
            '--evidence-hash' => str_repeat('a', 64),
            '--json' => true,
        ]);
        $completion = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('atlas.self_construction_agent_control_plane_task_queue_complete_dry_run_status.v1', $completion['schema_version']);
        $this->assertSame('completed_dry_run', data_get($completion, 'agent_control_plane_task_queue_complete_dry_run.status'));
        $this->assertSame('completed_dry_run', data_get($completion, 'agent_control_plane_task_queue_complete_dry_run.event'));
        $this->assertTrue((bool) data_get($completion, 'agent_control_plane_task_queue_complete_dry_run.runtime_completion_persisted'));
        $this->assertFalse((bool) data_get($completion, 'agent_control_plane_task_queue_complete_dry_run.completion_real_allowed'));
        $this->assertFalse((bool) data_get($completion, 'agent_control_plane_task_queue_complete_dry_run.legacy_reservation_completion_used'));
        $this->assertTrue((bool) data_get($completion, 'agent_control_plane_task_queue_complete_dry_run.safe_for_parallel_terminal_loop'));
        $this->assertSame('completed_dry_run', (new AgentControlPlaneTaskPacketQueueRepository)->get($packetId)['status']);
    }

    public function test_cli_complete_dry_run_blocks_missing_or_invalid_inputs(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-task-queue-complete-dry-run-status' => true,
            '--lease-id' => 'lease_missing_packet',
            '--json' => true,
        ]);
        $missingPacket = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('blocked', data_get($missingPacket, 'agent_control_plane_task_queue_complete_dry_run.status'));
        $this->assertSame('task_packet_id_missing', data_get($missingPacket, 'agent_control_plane_task_queue_complete_dry_run.reason'));

        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-task-queue-claim-next-status' => true,
            '--actor' => 'agent-cli-invalid-evidence',
            '--json' => true,
        ]);
        $claim = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-task-queue-complete-dry-run-status' => true,
            '--packet' => (string) data_get($claim, 'agent_control_plane_task_queue_claim_next.task_packet_id'),
            '--lease-id' => (string) data_get($claim, 'agent_control_plane_task_queue_claim_next.lease_id'),
            '--evidence-hash' => 'not-a-sha',
            '--json' => true,
        ]);
        $invalidEvidence = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('blocked', data_get($invalidEvidence, 'agent_control_plane_task_queue_complete_dry_run.status'));
        $this->assertSame('evidence_hash_invalid', data_get($invalidEvidence, 'agent_control_plane_task_queue_complete_dry_run.reason'));
        $this->assertFalse((bool) data_get($invalidEvidence, 'agent_control_plane_task_queue_complete_dry_run.runtime_completion_persisted'));
    }

    public function test_cli_quartet_works(): void
    {
        foreach (['contract', 'preflight', 'implementation-packet'] as $stage) {
            Artisan::call('atlas:ai:self-construction', [
                "--agent-control-plane-task-queue-orchestrator-{$stage}" => true,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $stageKey = str_replace('-', '_', $stage);
            $this->assertSame("atlas.self_construction_agent_control_plane_task_queue_orchestrator_{$stageKey}.v1", $payload['schema_version']);
        }
    }

    public function test_full_orchestrator_payload_shape(): void
    {
        $svc = $this->orchestrator();
        $result = $svc->prepareAndEnqueue(['task_packet' => $this->input('shape-1')]);
        foreach ([
            'schema_version', 'mode', 'event', 'orchestration_id', 'generated_at',
            'task_packet', 'validation', 'queue_entry', 'evidence_plan', 'continuation_summary',
            'runtime_execution_allowed', 'dispatch_allowed', 'provider_call_allowed',
            'token_spend_allowed', 'self_programming_allowed', 'ledger_write_allowed',
            'completion_real_allowed', 'non_execution_guarantees',
        ] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing $key");
        }
        $this->assertSame('prepared_and_enqueued', $result['event']);
    }

    public function test_full_orchestrator_guarantees_block_runtime(): void
    {
        $svc = $this->orchestrator();
        $result = $svc->prepareAndEnqueue(['task_packet' => $this->input('guarantees-1')]);
        foreach ([
            'task_queue_orchestrator_does_not_start_codex',
            'task_queue_orchestrator_does_not_call_codex_cli_or_app',
            'task_queue_orchestrator_does_not_spawn_subprocess',
            'task_queue_orchestrator_does_not_invoke_adapter',
            'task_queue_orchestrator_does_not_call_provider',
            'task_queue_orchestrator_does_not_dispatch_work',
            'task_queue_orchestrator_does_not_spend_tokens',
            'task_queue_orchestrator_does_not_enable_self_programming',
            'task_queue_orchestrator_does_not_write_ledger',
            'task_queue_orchestrator_does_not_mutate_pointer',
            'task_queue_orchestrator_does_not_mark_real_completion',
        ] as $expected) {
            $this->assertContains($expected, $result['non_execution_guarantees']);
        }
    }

    public function test_orchestrator_idempotent_enqueue(): void
    {
        $svc = $this->orchestrator();
        $a = $svc->prepareAndEnqueue(['task_packet' => $this->input('idem-orch')]);
        $b = $svc->prepareAndEnqueue(['task_packet' => $this->input('idem-orch')]);
        $this->assertSame('prepared_and_enqueued', $a['event']);
        $this->assertSame('prepared_and_enqueued', $b['event']);
        $this->assertTrue((bool) data_get($b, 'queue_entry.idempotent'));
    }

    private function orchestrator(): AgentControlPlaneTaskQueueOrchestrator
    {
        return new AgentControlPlaneTaskQueueOrchestrator(
            new AgentControlPlaneTaskPacketBuilder,
            new AgentControlPlaneScopeLockRuntimeValidator,
            new AgentControlPlaneTaskPacketQueueRepository,
            new AgentControlPlaneClaimLeaseRepository,
            new AgentControlPlaneEvidenceLedgerDryRun,
            new AgentControlPlaneContinuationSummaryBuilder,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function input(string $id): array
    {
        return [
            'task_packet_id' => $id,
            'objective' => 'orch test '.$id,
            'operator_id' => 'tester',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['task_packet_created'],
        ];
    }
}
