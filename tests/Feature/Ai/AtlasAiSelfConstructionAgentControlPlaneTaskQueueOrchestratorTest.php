<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesAgentControlPlaneTaskQueueOrchestrator;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneTaskQueueOrchestratorTest extends TestCase
{
    use MakesAgentControlPlaneTaskQueueOrchestrator;
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

    public function test_a3_claim_next_presweeps_expired_lease_and_reserves_to_new_agent(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-01T10:00:00Z'));
        $svc = $this->orchestrator();
        $svc->prepareAndEnqueue(['task_packet' => $this->input('reap-presweep')]);

        // Agent claims the task (queue -> claimed), then dies. Short TTL.
        $claim = $svc->claimNext('agent-dead', ['ttl_seconds' => 60]);
        $this->assertSame('claimed', $claim['event']);

        // TTL passes with no renewal — the lease is dead and the task is stranded in `claimed`.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-01T11:00:00Z'));

        // A3/MF-05: the next claimNext PRE-SWEEPS the expired lease (claimed -> claimable) and re-serves it.
        $reclaim = $svc->claimNext('agent-fresh');
        $this->assertSame('claimed', $reclaim['event'], 'the stranded task is recovered and served to a new agent');
        $this->assertSame('reap-presweep', $reclaim['task_packet_id']);

        CarbonImmutable::setTestNow();
    }

    public function test_claim_next_with_multiple_tags_requires_all_tags(): void
    {
        $svc = $this->orchestrator();
        $svc->prepareAndEnqueue([
            'task_packet' => $this->input('claim-tags-partial'),
            'queue' => ['tags' => ['lane-a']],
        ]);
        $full = $this->input('claim-tags-full');
        $full['objective'] = 'verify multi tag routing for an HTTP controller';
        $full['allowed_files'] = ['app/Http/Controllers/ClaimTagsFullController.php'];
        $full['scope_in'] = $full['allowed_files'];
        $full['acceptance_criteria'] = ['controller route packet remains independently claimable'];
        $svc->prepareAndEnqueue([
            'task_packet' => $full,
            'queue' => ['tags' => ['lane-a', 'worker-1']],
        ]);

        $result = $svc->claimNext('agent-tags', [
            'tags' => ['lane-a', 'worker-1'],
        ]);

        $this->assertSame('claimed', $result['event']);
        $this->assertSame('claim-tags-full', $result['task_packet_id']);
        $this->assertSame(['lane-a', 'worker-1'], data_get($result, 'queue_entry.tags'));
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
        $evidence = $this->completionEvidenceFor('dry-1', $claim['lease_id'], 'agent-1', [
            'files_changed' => ['app/Services/Ai/SelfConstruction/dry-1.php'],
            'commands_run' => ['php artisan test --filter=dry-1: passed'],
            'git_status_short' => ' M app/Services/Ai/SelfConstruction/dry-1.php',
        ]);
        $complete = $svc->completeDryRun('dry-1', $claim['lease_id'], $evidence);
        $this->assertSame('completed_dry_run', $complete['event']);
        $this->assertFalse($complete['completion_real_allowed']);
        $this->assertSame('valid', data_get($complete, 'evidence_validation.status'));
        $this->assertTrue(data_get($complete, 'evidence_validation.structured_completion_evidence_required'));
        $this->assertTrue(data_get($complete, 'evidence_validation.structured_completion_evidence_valid'));
        $this->assertSame([], data_get($complete, 'evidence_validation.missing_fields'));
        $this->assertSame(0, data_get($complete, 'evidence_validation.blocker_count'));
        $this->assertTrue(data_get($complete, 'evidence_validation.packet_id_matches'));
        $this->assertTrue(data_get($complete, 'evidence_validation.lease_id_matches'));
        $this->assertTrue(data_get($complete, 'evidence_validation.actor_matches'));
        $this->assertTrue(data_get($complete, 'evidence_validation.evidence_hash_matches_payload'));
        $this->assertTrue(data_get($complete, 'queue_claim_binding_verified'));
        $this->assertSame('claimed', data_get($complete, 'queue_status_at_completion'));
        $this->assertSame($claim['lease_id'], data_get($complete, 'queue_lease_id'));
        $this->assertSame('agent-1', data_get($complete, 'queue_agent_id'));
        $this->assertTrue(data_get($complete, 'evidence_validation.files_changed_within_allowed_scope'));
        $this->assertSame([], data_get($complete, 'evidence_validation.files_changed_outside_allowed_scope'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($complete, 'evidence_validation.evidence_validation_hash'));
        $this->assertSame($evidence['evidence_hash'], data_get($complete, 'evidence_validation.evidence_hash'));
        $this->assertSame($evidence['evidence_hash'], data_get($complete, 'evidence_validation.computed_evidence_hash'));
        $queue = (new AgentControlPlaneTaskPacketQueueRepository)->get('dry-1');
        $this->assertSame('completed_dry_run', $queue['status']);
        $receipt = collect((array) data_get($queue, 'receipts'))->firstWhere('receipt_kind', 'dry_run_completion_recorded');
        $this->assertSame('valid', data_get($receipt, 'evidence_validation_status'));
        $this->assertTrue(data_get($receipt, 'structured_completion_evidence_required'));
        $this->assertTrue(data_get($receipt, 'structured_completion_evidence_valid'));
        $this->assertTrue(data_get($receipt, 'queue_claim_binding_verified'));
        $this->assertTrue(data_get($receipt, 'files_changed_within_allowed_scope'));
    }

    public function test_complete_dry_run_blocks_when_evidence_hash_does_not_match_payload(): void
    {
        $svc = $this->orchestrator();
        $svc->prepareAndEnqueue(['task_packet' => $this->input('dry-hash-mismatch')]);
        $claim = $svc->claimNext('agent-hash-mismatch');
        $evidence = $this->completionEvidenceFor('dry-hash-mismatch', $claim['lease_id'], 'agent-hash-mismatch');
        $evidence['evidence_hash'] = str_repeat('d', 64);

        $blocked = $svc->completeDryRun('dry-hash-mismatch', $claim['lease_id'], $evidence);

        $this->assertSame('complete_dry_run_blocked', $blocked['event']);
        $this->assertSame('evidence_hash_mismatch', $blocked['reason']);
        $this->assertFalse(data_get($blocked, 'evidence_validation.structured_completion_evidence_valid'));
        $this->assertFalse(data_get($blocked, 'evidence_validation.evidence_hash_matches_payload'));
        $this->assertContains('evidence_hash_mismatch', data_get($blocked, 'evidence_validation.blockers'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($blocked, 'evidence_validation.computed_evidence_hash'));
        $this->assertSame('claimed', (new AgentControlPlaneTaskPacketQueueRepository)->get('dry-hash-mismatch')['status']);
    }

    public function test_complete_dry_run_accepts_canonical_nested_completion_evidence_hash(): void
    {
        $svc = $this->orchestrator();
        $svc->prepareAndEnqueue(['task_packet' => $this->input('dry-nested-hash')]);
        $claim = $svc->claimNext('agent-nested-hash');
        $nested = $this->completionEvidenceFor('dry-nested-hash', $claim['lease_id'], 'agent-nested-hash');
        $outer = [
            'operator_supplied_evidence_hash' => $nested['evidence_hash'],
            'completion_evidence' => $nested,
        ];

        $complete = $svc->completeDryRun('dry-nested-hash', $claim['lease_id'], $outer);

        $this->assertSame('completed_dry_run', $complete['event']);
        $this->assertSame('valid', data_get($complete, 'evidence_validation.status'));
        $this->assertTrue(data_get($complete, 'evidence_validation.evidence_hash_matches_payload'));
    }

    public function test_complete_dry_run_blocks_when_structured_evidence_binding_does_not_match_packet_or_lease(): void
    {
        $svc = $this->orchestrator();
        $svc->prepareAndEnqueue(['task_packet' => $this->input('dry-binding')]);
        $claim = $svc->claimNext('agent-binding');

        $blocked = $svc->completeDryRun('dry-binding', $claim['lease_id'], $this->completionEvidenceFor(
            'other-packet',
            $claim['lease_id'],
            'agent-binding',
            [
                'files_changed' => ['app/Services/Ai/SelfConstruction/dry-binding.php'],
                'commands_run' => ['php artisan test --filter=dry-binding: passed'],
                'git_status_short' => ' M app/Services/Ai/SelfConstruction/dry-binding.php',
            ],
        ));

        $this->assertSame('complete_dry_run_blocked', $blocked['event']);
        $this->assertSame('packet_id_mismatch', $blocked['reason']);
        $this->assertFalse(data_get($blocked, 'evidence_validation.structured_completion_evidence_valid'));
        $this->assertFalse(data_get($blocked, 'evidence_validation.packet_id_matches'));
        $this->assertTrue(data_get($blocked, 'evidence_validation.evidence_hash_matches_payload'));
        $this->assertContains('packet_id_mismatch', data_get($blocked, 'evidence_validation.blockers'));
        $this->assertSame('claimed', (new AgentControlPlaneTaskPacketQueueRepository)->get('dry-binding')['status']);
    }

    public function test_complete_dry_run_blocks_when_files_changed_escape_allowed_scope(): void
    {
        $svc = $this->orchestrator();
        $svc->prepareAndEnqueue(['task_packet' => $this->input('dry-scope-escape')]);
        $claim = $svc->claimNext('agent-scope-escape');
        $evidence = $this->completionEvidenceFor('dry-scope-escape', $claim['lease_id'], 'agent-scope-escape', [
            'files_changed' => [
                'app/Services/Ai/SelfConstruction/dry-scope-escape.php',
                'routes/api.php',
            ],
            'git_status_short' => " M app/Services/Ai/SelfConstruction/dry-scope-escape.php\n M routes/api.php",
        ]);

        $blocked = $svc->completeDryRun('dry-scope-escape', $claim['lease_id'], $evidence);

        $this->assertSame('complete_dry_run_blocked', $blocked['event']);
        $this->assertSame('files_changed_outside_allowed_scope', $blocked['reason']);
        $this->assertFalse(data_get($blocked, 'evidence_validation.structured_completion_evidence_valid'));
        $this->assertFalse(data_get($blocked, 'evidence_validation.files_changed_within_allowed_scope'));
        $this->assertSame(['routes/api.php'], data_get($blocked, 'evidence_validation.files_changed_outside_allowed_scope'));
        $this->assertContains('files_changed_outside_allowed_scope', data_get($blocked, 'evidence_validation.blockers'));
        $this->assertTrue(data_get($blocked, 'evidence_validation.evidence_hash_matches_payload'));
        $this->assertSame('claimed', (new AgentControlPlaneTaskPacketQueueRepository)->get('dry-scope-escape')['status']);
    }

    public function test_complete_dry_run_blocks_without_valid_evidence_hash(): void
    {
        $svc = $this->orchestrator();
        $svc->prepareAndEnqueue(['task_packet' => $this->input('dry-missing-evidence')]);
        $claim = $svc->claimNext('agent-1');

        $missing = $svc->completeDryRun('dry-missing-evidence', $claim['lease_id'], []);

        $this->assertSame('complete_dry_run_blocked', $missing['event']);
        $this->assertSame('evidence_hash_missing', $missing['reason']);
        $this->assertSame('blocked', data_get($missing, 'evidence_validation.status'));
        $this->assertSame('claimed', (new AgentControlPlaneTaskPacketQueueRepository)->get('dry-missing-evidence')['status']);
    }

    public function test_complete_dry_run_blocks_when_structured_evidence_is_incomplete(): void
    {
        $svc = $this->orchestrator();
        $svc->prepareAndEnqueue(['task_packet' => $this->input('dry-incomplete-evidence')]);
        $claim = $svc->claimNext('agent-1');
        $evidence = [
            'packet_id' => 'dry-incomplete-evidence',
            'lease_id' => $claim['lease_id'],
            'actor' => 'agent-1',
            'tests_or_gates_result' => 'passed',
            'implementation_notes' => 'Validated the scoped implementation and its behavioral proof.',
            'capability_delta' => 'Adds the bounded capability described by this packet.',
        ];
        $evidence['evidence_hash'] = AgentControlPlaneTaskQueueOrchestrator::canonicalCompletionEvidenceHash($evidence);
        $blocked = $svc->completeDryRun('dry-incomplete-evidence', $claim['lease_id'], $evidence);

        $this->assertSame('complete_dry_run_blocked', $blocked['event']);
        $this->assertSame('files_changed_missing', $blocked['reason']);
        $this->assertFalse(data_get($blocked, 'evidence_validation.structured_completion_evidence_valid'));
        $this->assertContains('files_changed', data_get($blocked, 'evidence_validation.missing_fields'));
        $this->assertContains('commands_run', data_get($blocked, 'evidence_validation.missing_fields'));
        $this->assertContains('git_status_short', data_get($blocked, 'evidence_validation.missing_fields'));
        $this->assertContains('git_diff_check_result', data_get($blocked, 'evidence_validation.missing_fields'));
        $this->assertContains('commands_run_missing', data_get($blocked, 'evidence_validation.blockers'));
        $this->assertContains('git_status_short_missing', data_get($blocked, 'evidence_validation.blockers'));
        $this->assertContains('git_diff_check_result_missing', data_get($blocked, 'evidence_validation.blockers'));
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

    public function test_complete_dry_run_blocks_when_queue_record_is_no_longer_claimed(): void
    {
        $svc = $this->orchestrator();
        $svc->prepareAndEnqueue(['task_packet' => $this->input('dry-requeued')]);
        $claim = $svc->claimNext('agent-requeued');
        (new AgentControlPlaneTaskPacketQueueRepository)->updateStatus('dry-requeued', 'blocked', [
            'reason' => 'synthetic_queue_state_drift',
        ]);

        $blocked = $svc->completeDryRun(
            'dry-requeued',
            $claim['lease_id'],
            $this->completionEvidenceFor('dry-requeued', $claim['lease_id'], 'agent-requeued'),
        );

        $this->assertSame('complete_dry_run_blocked', $blocked['event']);
        $this->assertSame('task_packet_not_claimed_for_completion', $blocked['reason']);
        $this->assertSame('blocked', $blocked['queue_status']);
        $this->assertFalse((bool) $blocked['completion_real_allowed']);
        $this->assertSame('blocked', (new AgentControlPlaneTaskPacketQueueRepository)->get('dry-requeued')['status']);
    }

    public function test_complete_dry_run_blocks_when_queue_claim_metadata_does_not_match_lease(): void
    {
        $svc = $this->orchestrator();
        $svc->prepareAndEnqueue(['task_packet' => $this->input('dry-queue-lease-mismatch')]);
        $claim = $svc->claimNext('agent-queue-lease-mismatch');
        $path = AgentControlPlaneTaskPacketQueueRepository::STORAGE_PREFIX.'/task_dry-queue-lease-mismatch.json';
        $record = json_decode((string) Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR);
        $record['metadata']['lease_id'] = 'foreign-lease-id';
        Storage::disk('local')->put($path, json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $blocked = $svc->completeDryRun(
            'dry-queue-lease-mismatch',
            $claim['lease_id'],
            $this->completionEvidenceFor('dry-queue-lease-mismatch', $claim['lease_id'], 'agent-queue-lease-mismatch'),
        );

        $this->assertSame('complete_dry_run_blocked', $blocked['event']);
        $this->assertSame('queue_lease_id_mismatch', $blocked['reason']);
        $this->assertSame('foreign-lease-id', $blocked['queue_lease_id']);
        $this->assertFalse((bool) $blocked['completion_real_allowed']);
        $this->assertSame('claimed', (new AgentControlPlaneTaskPacketQueueRepository)->get('dry-queue-lease-mismatch')['status']);
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

    public function test_cli_claim_next_preserves_all_queue_tags(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-task-queue-claim-next-status' => true,
            '--actor' => 'agent-cli-tagged',
            '--queue-tag' => ['cli-lane', 'cli-worker'],
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $packetId = (string) data_get($payload, 'agent_control_plane_task_queue_claim_next.task_packet_id');
        $record = (new AgentControlPlaneTaskPacketQueueRepository)->get($packetId);

        $this->assertSame('claimed', data_get($payload, 'agent_control_plane_task_queue_claim_next.status'));
        $this->assertSame(['cli-lane', 'cli-worker'], data_get($payload, 'agent_control_plane_task_queue_claim_next.queue_tags'));
        $this->assertSame('cli-lane', data_get($payload, 'agent_control_plane_task_queue_claim_next.claim_tag'));
        $this->assertStringContainsString('--queue-tag=cli-lane', data_get($payload, 'agent_control_plane_task_queue_claim_next.next_agent_command'));
        $this->assertStringContainsString('--queue-tag=cli-worker', data_get($payload, 'agent_control_plane_task_queue_claim_next.next_agent_command'));
        $this->assertSame(['cli-lane', 'cli-worker'], data_get($record, 'tags'));
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
        $queueRecord = (new AgentControlPlaneTaskPacketQueueRepository)->get($packetId);
        $allowedFile = (string) data_get($queueRecord, 'task_packet.normalized_scope.allowed_files.0', '');
        $this->assertNotSame('', $allowedFile);
        $evidence = $this->completionEvidenceFor($packetId, $leaseId, 'agent-cli-complete', [
            'files_changed' => [$allowedFile],
            'commands_run' => ['php artisan test --filter='.pathinfo($allowedFile, PATHINFO_FILENAME).': passed'],
            'git_status_short' => ' M '.$allowedFile,
        ]);
        foreach ((array) data_get($queueRecord, 'task_packet.required_evidence', []) as $requiredLabel) {
            $evidence[(string) $requiredLabel] ??= 'receipt:'.$packetId;
        }
        $evidence['evidence_hash'] = AgentControlPlaneTaskQueueOrchestrator::canonicalCompletionEvidenceHash($evidence);

        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-task-queue-complete-dry-run-status' => true,
            '--packet' => $packetId,
            '--lease-id' => $leaseId,
            '--actor' => 'agent-cli-complete',
            '--evidence-hash' => $evidence['evidence_hash'],
            '--completion-evidence-json' => json_encode($evidence, JSON_THROW_ON_ERROR),
            '--json' => true,
        ]);
        $completion = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('atlas.self_construction_agent_control_plane_task_queue_complete_dry_run_status.v1', $completion['schema_version']);
        // A1-SC-0003 fix: a persisted dry-run completion is a durable write and the envelope says so.
        $this->assertSame('mutating_agent_control_plane_task_queue_complete_dry_run_status', $completion['mode']);
        $this->assertTrue((bool) $completion['runtime_write_allowed']);
        $this->assertTrue((bool) $completion['runtime_write_performed']);
        $this->assertSame('completed_dry_run', data_get($completion, 'agent_control_plane_task_queue_complete_dry_run.status'));
        $this->assertSame('completed_dry_run', data_get($completion, 'agent_control_plane_task_queue_complete_dry_run.event'));
        $this->assertTrue((bool) data_get($completion, 'agent_control_plane_task_queue_complete_dry_run.runtime_completion_persisted'));
        $this->assertFalse((bool) data_get($completion, 'agent_control_plane_task_queue_complete_dry_run.completion_real_allowed'));
        $this->assertFalse((bool) data_get($completion, 'agent_control_plane_task_queue_complete_dry_run.legacy_reservation_completion_used'));
        $this->assertTrue((bool) data_get($completion, 'agent_control_plane_task_queue_complete_dry_run.safe_for_parallel_terminal_loop'));
        $this->assertTrue((bool) data_get($completion, 'agent_control_plane_task_queue_complete_dry_run.structured_completion_evidence_valid'));
        $this->assertTrue((bool) data_get($completion, 'agent_control_plane_task_queue_complete_dry_run.files_changed_within_allowed_scope'));
        $this->assertSame('valid', data_get($completion, 'agent_control_plane_task_queue_complete_dry_run.completion_evidence_validation_status'));
        $this->assertSame(0, data_get($completion, 'agent_control_plane_task_queue_complete_dry_run.completion_evidence_blocker_count'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($completion, 'agent_control_plane_task_queue_complete_dry_run.completion_evidence_validation_hash'));
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
            '--json' => true,
        ]);
        $missingEvidence = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('blocked', data_get($missingEvidence, 'agent_control_plane_task_queue_complete_dry_run.status'));
        $this->assertSame('evidence_hash_missing', data_get($missingEvidence, 'agent_control_plane_task_queue_complete_dry_run.reason'));

        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-task-queue-complete-dry-run-status' => true,
            '--packet' => (string) data_get($claim, 'agent_control_plane_task_queue_claim_next.task_packet_id'),
            '--lease-id' => (string) data_get($claim, 'agent_control_plane_task_queue_claim_next.lease_id'),
            '--evidence-hash' => 'not-a-sha',
            '--completion-evidence-json' => json_encode($this->completionEvidence(
                'not-a-sha',
                (string) data_get($claim, 'agent_control_plane_task_queue_claim_next.task_packet_id'),
                (string) data_get($claim, 'agent_control_plane_task_queue_claim_next.lease_id'),
                'agent-cli-invalid-evidence',
            ), JSON_THROW_ON_ERROR),
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

    public function test_mark_resolved_duplicate_replays_after_first_resolve(): void
    {
        $input = $this->input('resolve-dup');
        $proof = $this->committedTaskProof('resolve-dup', $input['allowed_files']);
        $svc = $this->orchestrator($proof['repository']);
        $svc->prepareAndEnqueue(['task_packet' => $input]);
        $claim = $svc->claimNext('agent-dup');
        $leaseId = (string) $claim['lease_id'];

        $first = $svc->markResolved('resolve-dup', $leaseId, 'agent-dup', $proof['commit_sha']);
        $this->assertSame('task_resolved', $first['event']);

        $second = $svc->markResolved('resolve-dup', $leaseId, 'agent-dup', $proof['commit_sha']);
        $this->assertSame('task_resolved', $second['event']);
        $this->assertTrue((bool) $second['replayed']);
    }

    public function test_mark_resolved_rejects_an_arbitrary_commit_identifier_without_releasing_the_lease(): void
    {
        $svc = $this->orchestrator();
        $svc->prepareAndEnqueue(['task_packet' => $this->input('resolve-unverified-commit')]);
        $claim = $svc->claimNext('agent-unverified-commit');

        $blocked = $svc->markResolved(
            'resolve-unverified-commit',
            (string) $claim['lease_id'],
            'agent-unverified-commit',
            'abc123',
        );

        $this->assertSame('resolve_blocked', $blocked['event']);
        $this->assertSame('commit_sha_invalid', $blocked['reason']);
        $this->assertSame('claimed', (new AgentControlPlaneTaskPacketQueueRepository)->get('resolve-unverified-commit')['status']);
        $this->assertNotNull((new AgentControlPlaneClaimLeaseRepository)->get((string) $claim['lease_id']));
    }

    public function test_mark_resolved_rejects_a_reachable_commit_that_changes_files_outside_the_packet_scope(): void
    {
        $input = $this->input('resolve-wrong-scope');
        $proof = $this->committedTaskProof('resolve-wrong-scope', ['app/Services/Ai/SelfConstruction/other.php']);
        $svc = $this->orchestrator($proof['repository']);
        $svc->prepareAndEnqueue(['task_packet' => $input]);
        $claim = $svc->claimNext('agent-wrong-scope');

        $blocked = $svc->markResolved('resolve-wrong-scope', (string) $claim['lease_id'], 'agent-wrong-scope', $proof['commit_sha']);

        $this->assertSame('resolve_blocked', $blocked['event']);
        $this->assertSame('commit_changed_files_outside_scope', $blocked['reason']);
        $this->assertSame('claimed', (new AgentControlPlaneTaskPacketQueueRepository)->get('resolve-wrong-scope')['status']);
    }

    public function test_mark_resolved_rejects_a_reachable_scoped_commit_without_the_packet_binding(): void
    {
        $input = $this->input('resolve-task-binding');
        $proof = $this->committedTaskProof('another-packet', $input['allowed_files']);
        $svc = $this->orchestrator($proof['repository']);
        $svc->prepareAndEnqueue(['task_packet' => $input]);
        $claim = $svc->claimNext('agent-task-binding');

        $blocked = $svc->markResolved('resolve-task-binding', (string) $claim['lease_id'], 'agent-task-binding', $proof['commit_sha']);

        $this->assertSame('resolve_blocked', $blocked['event']);
        $this->assertSame('commit_task_binding_missing', $blocked['reason']);
        $this->assertSame('claimed', (new AgentControlPlaneTaskPacketQueueRepository)->get('resolve-task-binding')['status']);
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

    /**
     * @return array<string,mixed>
     */
    private function completionEvidence(string $hash, string $packetId = 'packet-test', string $leaseId = 'lease-test', string $actor = 'agent-test'): array
    {
        return [
            'packet_id' => $packetId,
            'lease_id' => $leaseId,
            'actor' => $actor,
            'evidence_hash' => $hash,
            'files_changed' => ['app/Services/Ai/SelfConstruction/'.$packetId.'.php'],
            'commands_run' => ['php artisan test --filter='.$packetId.': passed'],
            'tests_or_gates_result' => 'passed',
            'implementation_notes' => 'Validated the scoped implementation and its behavioral proof.',
            'capability_delta' => 'Adds the bounded capability described by this packet.',
            'task_packet_created' => 'receipt:'.$packetId,
            'git_status_short' => ' M app/Services/Ai/SelfConstruction/'.$packetId.'.php',
            'git_diff_check_result' => 'clean',
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string,mixed>
     */
    private function completionEvidenceFor(string $packetId, string $leaseId, string $actor, array $overrides = []): array
    {
        $evidence = array_merge([
            'packet_id' => $packetId,
            'lease_id' => $leaseId,
            'actor' => $actor,
            'files_changed' => ['app/Services/Ai/SelfConstruction/'.$packetId.'.php'],
            'commands_run' => ['php artisan test --filter='.$packetId.': passed'],
            'tests_or_gates_result' => 'passed',
            'implementation_notes' => 'Validated the scoped implementation and its behavioral proof.',
            'capability_delta' => 'Adds the bounded capability described by this packet.',
            'task_packet_created' => 'receipt:'.$packetId,
            'git_status_short' => ' M app/Services/Ai/SelfConstruction/'.$packetId.'.php',
            'git_diff_check_result' => 'clean',
        ], $overrides);
        $evidence['evidence_hash'] = AgentControlPlaneTaskQueueOrchestrator::canonicalCompletionEvidenceHash($evidence);

        return $evidence;
    }

    // ── classifyPacket ──────────────────────────────────────────────────────────

    private function classifiablePacket(array $overrides = []): array
    {
        return array_merge([
            'allowed_files' => ['app/Services/Ai/SelfConstruction/Foo.php', 'tests/Unit/Ai/SelfConstruction/FooTest.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/Foo.php', 'tests/Unit/Ai/SelfConstruction/FooTest.php'],
            'acceptance_criteria' => ['php artisan test --filter=FooTest passes'],
            'duplicate_of' => '',
            'poison_risk_score' => 0.0,
            'is_stale' => false,
        ], $overrides);
    }

    public function test_classify_packet_serves_clean_packet(): void
    {
        $result = $this->orchestrator()->classifyPacket($this->classifiablePacket());

        $this->assertSame('serve', $result['decision']);
        $this->assertSame('packet_passes_classification_checks', $result['reason']);
    }

    public function test_classify_packet_quarantines_empty_allowed_files(): void
    {
        $result = $this->orchestrator()->classifyPacket($this->classifiablePacket(['allowed_files' => [], 'scope_in' => []]));

        $this->assertSame('quarantine', $result['decision']);
        $this->assertSame('implementation_missing_no_allowed_files', $result['reason']);
    }

    public function test_classify_packet_quarantines_test_only_packet(): void
    {
        $result = $this->orchestrator()->classifyPacket($this->classifiablePacket([
            'allowed_files' => ['tests/Unit/Ai/SelfConstruction/FooTest.php'],
            'scope_in' => ['tests/Unit/Ai/SelfConstruction/FooTest.php'],
        ]));

        $this->assertSame('quarantine', $result['decision']);
        $this->assertSame('test_only_packet_no_implementation_target', $result['reason']);
    }

    public function test_classify_packet_quarantines_duplicate_target(): void
    {
        $result = $this->orchestrator()->classifyPacket($this->classifiablePacket(['duplicate_of' => 'codex-meta-existing-task']));

        $this->assertSame('quarantine', $result['decision']);
        $this->assertSame('duplicate_target', $result['reason']);
    }

    public function test_classify_packet_quarantines_high_poison_risk(): void
    {
        $result = $this->orchestrator()->classifyPacket($this->classifiablePacket(['poison_risk_score' => 0.85]));

        $this->assertSame('quarantine', $result['decision']);
        $this->assertSame('high_poison_risk_score', $result['reason']);
    }

    public function test_classify_packet_defers_stale_packet(): void
    {
        $result = $this->orchestrator()->classifyPacket($this->classifiablePacket(['is_stale' => true]));

        $this->assertSame('defer', $result['decision']);
        $this->assertSame('stale_context_requires_refresh', $result['reason']);
    }

    public function test_classify_packet_repairs_when_allowed_files_not_covered_by_scope_in(): void
    {
        $result = $this->orchestrator()->classifyPacket($this->classifiablePacket([
            'allowed_files' => ['app/Services/Ai/SelfConstruction/Foo.php', 'app/Services/Ai/SelfConstruction/Bar.php', 'tests/Unit/Ai/SelfConstruction/FooTest.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/Foo.php', 'tests/Unit/Ai/SelfConstruction/FooTest.php'],
        ]));

        $this->assertSame('repair', $result['decision']);
        $this->assertSame('scope_in_does_not_cover_allowed_files', $result['reason']);
    }

    public function test_classify_packet_repairs_missing_acceptance_criteria(): void
    {
        $result = $this->orchestrator()->classifyPacket($this->classifiablePacket(['acceptance_criteria' => []]));

        $this->assertSame('repair', $result['decision']);
        $this->assertSame('missing_acceptance_criteria', $result['reason']);
    }

    public function test_classify_packet_priority_empty_files_outranks_all_other_checks(): void
    {
        $result = $this->orchestrator()->classifyPacket($this->classifiablePacket([
            'allowed_files' => [],
            'scope_in' => [],
            'duplicate_of' => 'something',
            'poison_risk_score' => 0.99,
            'is_stale' => true,
        ]));

        $this->assertSame('quarantine', $result['decision']);
        $this->assertSame('implementation_missing_no_allowed_files', $result['reason']);
    }

    public function test_classify_packet_priority_duplicate_outranks_poison_and_stale(): void
    {
        $result = $this->orchestrator()->classifyPacket($this->classifiablePacket([
            'duplicate_of' => 'something',
            'poison_risk_score' => 0.99,
            'is_stale' => true,
        ]));

        $this->assertSame('quarantine', $result['decision']);
        $this->assertSame('duplicate_target', $result['reason']);
    }

    public function test_classify_packet_reads_normalized_scope_when_present(): void
    {
        $result = $this->orchestrator()->classifyPacket([
            'normalized_scope' => [
                'allowed_files' => ['app/Foo.php'],
                'scope_in' => ['app/Foo.php'],
            ],
            'acceptance_criteria' => ['php artisan test passes'],
        ]);

        $this->assertSame('serve', $result['decision']);
    }
}
