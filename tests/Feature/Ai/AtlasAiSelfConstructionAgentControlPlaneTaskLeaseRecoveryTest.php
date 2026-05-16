<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskLeaseRecoveryService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneTaskLeaseRecoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15T10:00:00Z'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_constants_canonical(): void
    {
        $this->assertSame(
            'atlas.self_construction.agent_control_plane_task_lease_recovery.v1',
            AgentControlPlaneTaskLeaseRecoveryService::SCHEMA_VERSION,
        );
        $this->assertSame(
            'persistent_local_agent_control_plane_task_lease_recovery',
            AgentControlPlaneTaskLeaseRecoveryService::MODE,
        );
    }

    public function test_expired_lease_returns_task_to_claimable(): void
    {
        $svc = $this->recoveryService();
        $orchestrator = $this->orchestrator();
        $orchestrator->prepareAndEnqueue(['task_packet' => $this->input('expired-1')]);

        $claim = $orchestrator->claimNext('agent-a', ['ttl_seconds' => 60]);
        $this->assertSame('claimed', $claim['event']);
        $leaseId = (string) $claim['lease_id'];

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15T10:05:00Z'));

        $result = $svc->recoverExpiredLeases(['actor' => 'operator-x']);

        $this->assertSame('recover_expired_leases', $result['event']);
        $this->assertSame(1, $result['recovered_count']);
        $this->assertSame(0, $result['skipped_count']);
        $recovered = $result['recovered'][0];
        $this->assertSame('expired-1', $recovered['task_packet_id']);
        $this->assertSame($leaseId, $recovered['lease_id']);
        $this->assertSame('agent-a', $recovered['previous_agent_id']);
        $this->assertSame('claimable', $recovered['final_queue_status']);
        $this->assertSame(
            AgentControlPlaneTaskLeaseRecoveryService::REASON_LEASE_EXPIRED,
            $recovered['recovery_reason'],
        );

        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $record = $queue->get('expired-1');
        $this->assertSame('claimable', $record['status']);
    }

    public function test_active_lease_is_not_recovered(): void
    {
        $svc = $this->recoveryService();
        $orchestrator = $this->orchestrator();
        $orchestrator->prepareAndEnqueue(['task_packet' => $this->input('active-1')]);
        $claim = $orchestrator->claimNext('agent-b', ['ttl_seconds' => 600]);
        $this->assertSame('claimed', $claim['event']);

        $expired = $svc->recoverExpiredLeases(['actor' => 'operator-x']);
        $orphan = $svc->recoverOrphanedClaims(['actor' => 'operator-x']);

        $this->assertSame(0, $expired['recovered_count']);
        $this->assertSame(0, $orphan['recovered_count']);
        $this->assertSame(1, $orphan['claimed_record_count']);
        $this->assertSame('lease_still_active', $orphan['skipped'][0]['skip_reason']);

        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $this->assertSame('claimed', $queue->get('active-1')['status']);
    }

    public function test_orphaned_claimed_task_is_recovered(): void
    {
        $svc = $this->recoveryService();
        $orchestrator = $this->orchestrator();
        $orchestrator->prepareAndEnqueue(['task_packet' => $this->input('orphan-1')]);
        $claim = $orchestrator->claimNext('agent-c', ['ttl_seconds' => 600]);
        $leaseId = (string) $claim['lease_id'];

        // Simulate lease file disappearing (e.g. local fs scrubbed, registry drift).
        $disk = Storage::disk('local');
        $leasePath = AgentControlPlaneClaimLeaseRepository::STORAGE_PREFIX.'/'.$this->safeId($leaseId).'.json';
        $this->assertTrue($disk->exists($leasePath));
        $disk->delete($leasePath);

        $orphan = $svc->recoverOrphanedClaims(['actor' => 'operator-y']);

        $this->assertSame(1, $orphan['recovered_count']);
        $this->assertSame(0, $orphan['skipped_count']);
        $recovered = $orphan['recovered'][0];
        $this->assertSame('orphan-1', $recovered['task_packet_id']);
        $this->assertSame($leaseId, $recovered['lease_id']);
        $this->assertSame(
            AgentControlPlaneTaskLeaseRecoveryService::REASON_LEASE_EXPIRED_ORPHANED,
            $recovered['recovery_reason'],
        );

        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $this->assertSame('claimable', $queue->get('orphan-1')['status']);
    }

    public function test_completed_dry_run_is_not_reopened(): void
    {
        $svc = $this->recoveryService();
        $orchestrator = $this->orchestrator();
        $orchestrator->prepareAndEnqueue(['task_packet' => $this->input('done-1')]);
        $claim = $orchestrator->claimNext('agent-d');
        $orchestrator->completeDryRun('done-1', (string) $claim['lease_id']);

        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $this->assertSame('completed_dry_run', $queue->get('done-1')['status']);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15T20:00:00Z'));

        $expired = $svc->recoverExpiredLeases(['actor' => 'operator-z']);
        $orphan = $svc->recoverOrphanedClaims(['actor' => 'operator-z']);
        $resume = $svc->buildResumePacket('done-1');

        $this->assertSame(0, $expired['recovered_count']);
        $this->assertSame(0, $orphan['recovered_count']);
        $this->assertSame('build_resume_packet_blocked', $resume['event']);
        $this->assertSame('task_is_terminal', $resume['reason']);
        $this->assertSame('completed_dry_run', $queue->get('done-1')['status']);
    }

    public function test_cancelled_task_is_not_reopened(): void
    {
        $svc = $this->recoveryService();
        $orchestrator = $this->orchestrator();
        $orchestrator->prepareAndEnqueue(['task_packet' => $this->input('cancel-1')]);

        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        // claimable → cancelled is allowed.
        $queue->updateStatus('cancel-1', 'cancelled');
        $this->assertSame('cancelled', $queue->get('cancel-1')['status']);

        $expired = $svc->recoverExpiredLeases();
        $orphan = $svc->recoverOrphanedClaims();
        $inspect = $svc->inspectRecoverability(['packet' => 'cancel-1']);
        $resume = $svc->buildResumePacket('cancel-1');

        $this->assertSame(0, $expired['recovered_count']);
        $this->assertSame(0, $orphan['recovered_count']);
        $this->assertSame(1, $inspect['inspected_count']);
        $this->assertSame(
            AgentControlPlaneTaskLeaseRecoveryService::RECOVERABILITY_TERMINAL,
            $inspect['classifications'][0]['classification'],
        );
        $this->assertSame('build_resume_packet_blocked', $resume['event']);
        $this->assertSame('task_is_terminal', $resume['reason']);
        $this->assertSame('cancelled', $queue->get('cancel-1')['status']);
    }

    public function test_recovery_appends_receipt(): void
    {
        $svc = $this->recoveryService();
        $orchestrator = $this->orchestrator();
        $orchestrator->prepareAndEnqueue(['task_packet' => $this->input('receipt-1')]);
        $orchestrator->claimNext('agent-r', ['ttl_seconds' => 60]);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15T10:05:00Z'));

        $svc->recoverExpiredLeases(['actor' => 'operator-receipt']);

        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $record = $queue->get('receipt-1');
        $kinds = array_column((array) $record['receipts'], 'receipt_kind');

        $this->assertContains(
            AgentControlPlaneTaskLeaseRecoveryService::RECEIPT_TASK_LEASE_RECOVERY_EXECUTED,
            $kinds,
        );

        $executedReceipt = $this->firstReceiptOfKind(
            (array) $record['receipts'],
            AgentControlPlaneTaskLeaseRecoveryService::RECEIPT_TASK_LEASE_RECOVERY_EXECUTED,
        );
        $this->assertSame('operator-receipt', $executedReceipt['recovered_by']);
        $this->assertSame('agent-r', $executedReceipt['previous_agent_id']);
        $this->assertSame(
            AgentControlPlaneTaskLeaseRecoveryService::REASON_LEASE_EXPIRED,
            $executedReceipt['recovery_reason'],
        );
        $this->assertSame('claimable', $executedReceipt['final_queue_status']);
    }

    public function test_resume_packet_has_enough_context(): void
    {
        $svc = $this->recoveryService();
        $orchestrator = $this->orchestrator();
        $orchestrator->prepareAndEnqueue(['task_packet' => $this->input('resume-1')]);
        $claim = $orchestrator->claimNext('agent-resume', ['ttl_seconds' => 60]);
        $leaseId = (string) $claim['lease_id'];

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15T10:05:00Z'));

        $svc->recoverExpiredLeases(['actor' => 'operator-resume']);

        $resume = $svc->buildResumePacket('resume-1');
        $this->assertSame('build_resume_packet_ready', $resume['event']);
        $packet = $resume['resume_packet'];

        $this->assertSame('resume-1', $packet['task_packet_id']);
        $this->assertSame('claimable', $packet['queue_status']);
        $this->assertSame('agent-resume', $packet['previous_agent_id']);
        $this->assertSame($leaseId, $packet['previous_lease_id']);
        $this->assertNotEmpty($packet['receipts']);
        $this->assertNotNull($packet['last_history_event']);
        $this->assertIsArray($packet['continuation_summary']);
        $this->assertArrayHasKey('continuation_hash', $packet['continuation_summary']);
        $this->assertSame('reclaim_via_orchestrator', $packet['safe_next_action']);
        $this->assertStringContainsString(
            '--agent-control-plane-task-queue-claim-next-status',
            (string) $packet['recommended_claim_command'],
        );
    }

    public function test_resume_packet_blocks_unknown_task(): void
    {
        $svc = $this->recoveryService();
        $result = $svc->buildResumePacket('unknown-task');
        $this->assertSame('build_resume_packet_blocked', $result['event']);
        $this->assertSame('task_packet_not_found', $result['reason']);
    }

    public function test_cli_status_runs_end_to_end_without_dispatch(): void
    {
        $orchestrator = $this->orchestrator();
        $orchestrator->prepareAndEnqueue(['task_packet' => $this->input('cli-1')]);
        $claim = $orchestrator->claimNext('agent-cli-recovery', ['ttl_seconds' => 60]);
        $packetId = 'cli-1';
        $leaseId = (string) $claim['lease_id'];
        $this->assertNotEmpty($leaseId);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15T10:10:00Z'));

        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-task-lease-recovery-status' => true,
            '--packet' => $packetId,
            '--actor' => 'operator-cli',
            '--reason' => 'manual_recovery_drill',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(
            'atlas.self_construction_agent_control_plane_task_lease_recovery_status.v1',
            $payload['schema_version'],
        );
        $this->assertSame('available', data_get($payload, 'agent_control_plane_task_lease_recovery_status.status'));
        $this->assertSame('operator-cli', data_get($payload, 'agent_control_plane_task_lease_recovery_status.actor'));
        $this->assertSame('manual_recovery_drill', data_get($payload, 'agent_control_plane_task_lease_recovery_status.reason'));
        $this->assertSame($packetId, data_get($payload, 'agent_control_plane_task_lease_recovery_status.task_packet_filter'));
        $this->assertSame(1, (int) data_get($payload, 'agent_control_plane_task_lease_recovery_status.expired_recovered_count'));
        $this->assertSame(
            'build_resume_packet_ready',
            data_get($payload, 'agent_control_plane_task_lease_recovery_status.resume_packet_event'),
        );

        $resumePacket = data_get($payload, 'agent_control_plane_task_lease_recovery.resume_packet.resume_packet');
        $this->assertSame('claimable', data_get($resumePacket, 'queue_status'));
        $this->assertSame('agent-cli-recovery', data_get($resumePacket, 'previous_agent_id'));
        $this->assertSame($leaseId, data_get($resumePacket, 'previous_lease_id'));

        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $this->assertSame('claimable', $queue->get($packetId)['status']);
    }

    public function test_cli_quartet_returns_canonical_payloads(): void
    {
        foreach (['contract', 'preflight', 'implementation-packet'] as $stage) {
            Artisan::call('atlas:ai:self-construction', [
                "--agent-control-plane-task-lease-recovery-{$stage}" => true,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $stageKey = str_replace('-', '_', $stage);
            $this->assertSame(
                "atlas.self_construction_agent_control_plane_task_lease_recovery_{$stageKey}.v1",
                $payload['schema_version'],
            );
        }
    }

    public function test_runtime_flags_false(): void
    {
        $svc = $this->recoveryService();
        $result = $svc->recoverExpiredLeases();
        $this->assertFalse($result['runtime_execution_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['self_programming_allowed']);
        $this->assertFalse($result['ledger_write_allowed']);
        $this->assertFalse($result['completion_real_allowed']);
        $this->assertContains(
            'task_lease_recovery_does_not_call_provider',
            $result['non_execution_guarantees'],
        );
        $this->assertContains(
            'task_lease_recovery_does_not_reopen_terminal_tasks',
            $result['non_execution_guarantees'],
        );
        $this->assertContains(
            'task_lease_recovery_does_not_mark_real_completion',
            $result['non_execution_guarantees'],
        );

        $flags = $svc->runtimeFlags();
        foreach ($flags as $value) {
            $this->assertFalse($value);
        }
    }

    public function test_inspect_recoverability_classifies_correctly(): void
    {
        $svc = $this->recoveryService();
        $orchestrator = $this->orchestrator();
        $orchestrator->prepareAndEnqueue(['task_packet' => $this->input('insp-active')]);
        $orchestrator->prepareAndEnqueue(['task_packet' => $this->input('insp-claimable')]);
        $orchestrator->prepareAndEnqueue(['task_packet' => $this->input('insp-expired')]);

        $orchestrator->claimNext('agent-active', ['ttl_seconds' => 3600]);
        // Now claim the second; it should grab insp-claimable next.
        $claim2 = $orchestrator->claimNext('agent-expired', ['ttl_seconds' => 60]);
        $this->assertSame('claimed', $claim2['event']);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15T10:05:00Z'));

        $inspect = $svc->inspectRecoverability();
        $totals = $inspect['totals_by_classification'];

        $this->assertGreaterThanOrEqual(1, $totals[AgentControlPlaneTaskLeaseRecoveryService::RECOVERABILITY_ACTIVE_LEASE]);
        $this->assertGreaterThanOrEqual(1, $totals[AgentControlPlaneTaskLeaseRecoveryService::RECOVERABILITY_RECOVERABLE_EXPIRED]);
    }

    private function recoveryService(): AgentControlPlaneTaskLeaseRecoveryService
    {
        return new AgentControlPlaneTaskLeaseRecoveryService;
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
            'objective' => 'lease recovery test '.$id,
            'operator_id' => 'tester',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['task_packet_created'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $receipts
     * @return array<string, mixed>
     */
    private function firstReceiptOfKind(array $receipts, string $kind): array
    {
        foreach ($receipts as $receipt) {
            if ((string) ($receipt['receipt_kind'] ?? '') === $kind) {
                return $receipt;
            }
        }

        return [];
    }

    private function safeId(string $id): string
    {
        return preg_replace('/[^A-Za-z0-9_\-]/', '_', $id) ?? $id;
    }
}
