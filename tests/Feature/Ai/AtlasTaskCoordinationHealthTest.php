<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\AtlasTaskCoordinationHealthService;
use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PART 2 · axis 10 — the coordination health panel reports the true serving cross-cut and the integrity flags.
 */
final class AtlasTaskCoordinationHealthTest extends TestCase
{
    private string $envFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->envFile = sys_get_temp_dir().'/atlas-health-env-'.bin2hex(random_bytes(5)).'.env';
        file_put_contents($this->envFile, "ATLAS_LOOP_MASTER_ENABLED=true\n");
        AtlasLoopMasterSwitch::$envPathOverride = $this->envFile;
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        if ($this->envFile !== '') {
            @unlink($this->envFile);
        }
        parent::tearDown();
    }

    public function test_snapshot_reports_distribution_leases_and_a_healthy_state(): void
    {
        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => $this->input('a')]);
        $orch->prepareAndEnqueue(['task_packet' => $this->input('b')]);
        $serving = new AtlasTaskServingService($orch);

        // Claim 'a' (a held lease + a claimed record); leave 'b' claimable.
        $served = $serving->next('client-1');
        $this->assertSame('served', $served['status']);

        $health = new AtlasTaskCoordinationHealthService(
            new AgentControlPlaneTaskPacketQueueRepository,
            new AgentControlPlaneClaimLeaseRepository,
        );
        $snap = $health->snapshot();

        $this->assertSame(AtlasTaskCoordinationHealthService::SCHEMA, $snap['schema']);
        $this->assertSame(1, $snap['claimable_depth'], 'b is still claimable');
        $this->assertSame(1, $snap['claimed_records'], 'a is claimed');
        $this->assertSame(1, $snap['active_leases'], 'a holds one active lease');
        $this->assertTrue($snap['leases_match_claimed'], 'one active lease ↔ one claimed record (no leak)');
        $this->assertTrue($snap['healthy'], 'no integrity breach');
        $this->assertFalse($snap['health_flags']['lease_leak_detected']);
        $this->assertFalse($snap['health_flags']['dry_queue']);
    }

    public function test_snapshot_flags_a_quarantined_packet_and_a_dry_queue(): void
    {
        $orch = $this->orchestrator();
        // Legacy backlog: the ONLY packet is deficient, so serving quarantines it and the queue goes dry.
        $this->rawEnqueue($this->input('doomed', acceptance: [], evidence: []));
        $serving = new AtlasTaskServingService($orch);

        $res = $serving->next('client-1');
        $this->assertSame('no_self_sufficient_task', $res['status']);

        $snap = (new AtlasTaskCoordinationHealthService(
            new AgentControlPlaneTaskPacketQueueRepository,
            new AgentControlPlaneClaimLeaseRepository,
        ))->snapshot();

        $this->assertSame(1, $snap['quarantined_count'], 'the doomed packet is blocked');
        $this->assertTrue($snap['health_flags']['has_quarantined_packets']);
        $this->assertTrue($snap['health_flags']['dry_queue'], 'nothing claimable remains');
        // A quarantined packet released its lease, so leases still match claimed (both zero) — no leak.
        $this->assertTrue($snap['leases_match_claimed']);
        $this->assertTrue($snap['healthy'], 'a dry queue is operational, not an integrity breach');
    }

    public function test_health_cli_front_door_prints_json(): void
    {
        $this->orchestrator()->prepareAndEnqueue(['task_packet' => $this->input('cli-health')]);

        $exit = \Illuminate\Support\Facades\Artisan::call('atlas:task:health', ['--json' => true]);
        $this->assertSame(0, $exit);
        $out = \Illuminate\Support\Facades\Artisan::output();
        $this->assertStringContainsString('"schema": "'.AtlasTaskCoordinationHealthService::SCHEMA.'"', $out);
        $this->assertStringContainsString('"claimable_depth": 1', $out);
    }

    public function test_malformed_sweep_quarantines_doomed_claimable_packets_before_workers_pull(): void
    {
        $orch = $this->orchestrator();
        $this->rawEnqueue($this->input('sweep-doomed', acceptance: [], evidence: []));
        $orch->prepareAndEnqueue(['task_packet' => $this->input('sweep-good')]);

        $dry = $orch->sweepMalformedClaimableTasks(dryRun: true, actor: 'test-sweep');
        $this->assertSame(1, $dry['would_block_count']);
        $this->assertSame('claimable', (string) ((new AgentControlPlaneTaskPacketQueueRepository)->get('sweep-doomed')['status'] ?? ''));

        $sweep = $orch->sweepMalformedClaimableTasks(actor: 'test-sweep');
        $this->assertSame(1, $sweep['blocked_count']);
        $this->assertContains('missing_acceptance_criteria', $sweep['blocked'][0]['blocking_deficiencies']);

        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $this->assertSame('blocked', (string) ($queue->get('sweep-doomed')['status'] ?? ''));
        $this->assertSame('claimable', (string) ($queue->get('sweep-good')['status'] ?? ''));

        $snap = (new AtlasTaskCoordinationHealthService($queue, new AgentControlPlaneClaimLeaseRepository))->snapshot();
        $this->assertSame(1, $snap['claimable_depth']);
        $this->assertSame(1, $snap['servable_now']);
        $this->assertSame(1, $snap['quarantined_count']);

        $served = (new AtlasTaskServingService($orch))->next('client-1');
        $this->assertSame('served', $served['status']);
        $this->assertSame('sweep-good', $served['task']['task_packet_id']);
    }

    public function test_malformed_sweep_cli_front_door_prints_json(): void
    {
        $this->rawEnqueue($this->input('cli-sweep-doomed', acceptance: [], evidence: []));

        $exit = \Illuminate\Support\Facades\Artisan::call('atlas:task:sweep-malformed', ['--json' => true]);
        $this->assertSame(0, $exit);
        $out = \Illuminate\Support\Facades\Artisan::output();

        $this->assertStringContainsString('"schema": "atlas.task_serving.malformed_sweep.v1"', $out);
        $this->assertStringContainsString('"blocked_count": 1', $out);
        $this->assertSame('blocked', (string) ((new AgentControlPlaneTaskPacketQueueRepository)->get('cli-sweep-doomed')['status'] ?? ''));
    }

    public function test_malformed_sweep_quarantines_scope_repair_test_only_poison_packets(): void
    {
        $target = 'app/Services/Ai/AutonomousEvolution/Memory/AtlasLoopGroundedProjectionRoles.php';
        $this->rawEnqueue([
            'task_packet_id' => 'scope-repair-test-only-poison',
            'objective' => 'Implement AtlasLoopGroundedProjectionRoles memory-grounded role seeds. Scope repair: '.$target.' was removed from allowed_files because Atlas cannot safely commit forbidden self-targets. Implement only the remaining allowed_files and do not edit the removed path(s).',
            'operator_id' => 'tester',
            'allowed_files' => ['tests/Unit/Ai/AutonomousEvolution/Memory/AtlasLoopGroundedProjectionRolesTest.php'],
            'scope_in' => ['tests/Unit/Ai/AutonomousEvolution/Memory/AtlasLoopGroundedProjectionRolesTest.php'],
            'forbidden_files' => [$target],
            'acceptance_criteria' => ['AtlasLoopGroundedProjectionRoles exposes deterministic role seeds'],
            'required_evidence' => ['tests_or_gates_result'],
        ]);

        $dry = $this->orchestrator()->sweepMalformedClaimableTasks(dryRun: true, actor: 'test-sweep');
        $this->assertSame(1, $dry['would_block_count']);
        $this->assertContains('scope_repair_removed_required_target_from_allowed_files', $dry['would_block'][0]['blocking_deficiencies']);

        $sweep = $this->orchestrator()->sweepMalformedClaimableTasks(actor: 'test-sweep');
        $this->assertSame(1, $sweep['blocked_count']);
        $this->assertSame('blocked', (string) ((new AgentControlPlaneTaskPacketQueueRepository)->get('scope-repair-test-only-poison')['status'] ?? ''));
    }

    public function test_malformed_sweep_quarantines_test_only_singleton_microtasks(): void
    {
        $this->rawEnqueue($this->testOnlyInput('cerebro4-singleton'));

        $dry = $this->orchestrator()->sweepMalformedClaimableTasks(dryRun: true, actor: 'test-sweep');
        $this->assertSame(1, $dry['would_block_count']);
        $this->assertContains('test_only_microtask_requires_contract', $dry['would_block'][0]['blocking_deficiencies']);

        $sweep = $this->orchestrator()->sweepMalformedClaimableTasks(actor: 'test-sweep');
        $this->assertSame(1, $sweep['blocked_count']);
        $this->assertSame('blocked', (string) ((new AgentControlPlaneTaskPacketQueueRepository)->get('cerebro4-singleton')['status'] ?? ''));
    }

    public function test_test_only_behavior_matrix_remains_worker_servable(): void
    {
        $this->rawEnqueue($this->testOnlyInput('test-matrix', [
            'target_behavior' => 'FooGate decision policy for approved, rejected, and malformed inputs.',
            'risk_if_missing' => 'A regression could silently approve unsafe work or reject valid work.',
            'min_distinct_cases' => 3,
            'cases' => [
                'approved input returns an allow decision',
                'unsafe input returns a reject decision',
                'malformed input returns a stable error decision',
            ],
        ]));

        $served = (new AtlasTaskServingService($this->orchestrator()))->next('client-1');

        $this->assertSame('served', $served['status']);
        $this->assertSame('test-matrix', $served['task']['task_packet_id']);
        $this->assertSame([], $served['task']['packet_quality']['blocking_deficiencies']);
    }

    public function test_repair_blocked_forbidden_self_target_reopens_same_task_id_with_safe_scope(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $this->rawEnqueue($this->forbiddenInput('repair-forbidden'));
        $queue->updateStatus('repair-forbidden', 'blocked', [
            'reason' => 'packet_not_self_sufficient_sweep',
            'blocking_deficiencies' => ['forbidden_self_target_in_allowed_files'],
        ]);

        $repair = $this->orchestrator()->repairBlockedForbiddenSelfTargetTasks(actor: 'test-repair');
        $this->assertSame(1, $repair['repaired_count']);
        $this->assertSame(0, $repair['retired_count']);

        $record = $queue->get('repair-forbidden');
        $this->assertSame('claimable', (string) ($record['status'] ?? ''));
        $this->assertSame('repair-forbidden', (string) data_get($record, 'task_packet.task_packet_id'));
        $this->assertNotContains('config/atlas.php', (array) data_get($record, 'task_packet.normalized_scope.allowed_files', []));
        $this->assertContains('config/atlas.php', (array) data_get($record, 'task_packet.normalized_scope.forbidden_files', []));

        $served = (new AtlasTaskServingService($this->orchestrator()))->next('client-1');
        $this->assertSame('served', $served['status']);
        $this->assertSame('repair-forbidden', $served['task']['task_packet_id']);
    }

    public function test_repair_blocked_cli_front_door_prints_json(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $this->rawEnqueue($this->forbiddenInput('cli-repair-forbidden'));
        $queue->updateStatus('cli-repair-forbidden', 'blocked', [
            'reason' => 'packet_not_self_sufficient_sweep',
            'blocking_deficiencies' => ['forbidden_self_target_in_allowed_files'],
        ]);

        $exit = \Illuminate\Support\Facades\Artisan::call('atlas:task:repair-blocked', ['--json' => true]);
        $this->assertSame(0, $exit);
        $out = \Illuminate\Support\Facades\Artisan::output();

        $this->assertStringContainsString('"schema": "atlas.task_serving.blocked_repair.v1"', $out);
        $this->assertStringContainsString('"repaired_count": 1', $out);
        $this->assertStringContainsString('"retired_count": 0', $out);
        $this->assertSame('claimable', (string) ($queue->get('cli-repair-forbidden')['status'] ?? ''));
    }

    public function test_repair_blocked_forbidden_self_target_retires_empty_scope_packets(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $this->rawEnqueue($this->forbiddenOnlyInput('repair-empty-scope'));
        $queue->updateStatus('repair-empty-scope', 'blocked', [
            'reason' => 'packet_not_self_sufficient_sweep',
            'blocking_deficiencies' => ['forbidden_self_target_in_allowed_files'],
        ]);

        $repair = $this->orchestrator()->repairBlockedForbiddenSelfTargetTasks(actor: 'test-repair');
        $this->assertSame(0, $repair['repaired_count']);
        $this->assertSame(1, $repair['retired_count']);
        $this->assertSame(0, $repair['unrepairable_count']);

        $record = $queue->get('repair-empty-scope');
        $this->assertSame('cancelled', (string) ($record['status'] ?? ''));
        $this->assertSame('unrepairable_empty_allowed_files_after_forbidden_self_target_repair', (string) data_get($record, 'metadata.reason'));
        $this->assertSame('blocked_packet_retired_empty_scope_after_forbidden_self_target_repair', (string) data_get($record, 'receipts.0.receipt_kind'));
    }

    public function test_recoverable_expired_lease_strand_is_healthy_not_degraded(): void
    {
        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => $this->input('recoverable-lease-test')]);
        $serving = new AtlasTaskServingService($orch);

        $served = $serving->next('lease-test-client');
        $this->assertSame('served', $served['status'], 'task must be served to create a lease');
        $leaseId = (string) ($served['task']['lease_id'] ?? '');
        $this->assertNotEmpty($leaseId);

        // Backdate the lease to appear expired without sleeping:
        // overwrite the lease file and registry entry directly on the faked disk.
        $prefix = AgentControlPlaneClaimLeaseRepository::STORAGE_PREFIX;
        $leasePath = $prefix.'/'.$leaseId.'.json';

        $raw = \Illuminate\Support\Facades\Storage::disk('local')->get($leasePath);
        $this->assertNotNull($raw);
        $lease = json_decode((string) $raw, true);
        $lease['expires_at_unix'] = 1;
        \Illuminate\Support\Facades\Storage::disk('local')->put($leasePath, (string) json_encode($lease));

        $regRaw = \Illuminate\Support\Facades\Storage::disk('local')->get(AgentControlPlaneClaimLeaseRepository::REGISTRY_PATH);
        $this->assertNotNull($regRaw);
        $registry = json_decode((string) $regRaw, true);
        foreach ($registry['entries'] as &$entry) {
            if ((string) ($entry['lease_id'] ?? '') === $leaseId) {
                $entry['expires_at_unix'] = 1;
            }
        }
        unset($entry);
        \Illuminate\Support\Facades\Storage::disk('local')->put(AgentControlPlaneClaimLeaseRepository::REGISTRY_PATH, (string) json_encode($registry));

        // After expiry: activeLeases() → 0, claimed queue record → 1, leaseLeak = true.
        // recoverableTotal > 0 (claimed + expired lease = recoverable strand).
        // Fix: lease_leak_detected = leaseLeak && recoverableTotal === 0 = false → healthy.
        $health = new AtlasTaskCoordinationHealthService(
            new AgentControlPlaneTaskPacketQueueRepository,
            new AgentControlPlaneClaimLeaseRepository,
        );
        $snap = $health->snapshot();

        $this->assertTrue($snap['healthy'], 'recoverable expired-lease strand must not degrade coordination health');
        $this->assertFalse($snap['health_flags']['lease_leak_detected'], 'lease_leak_detected must be false when strand is recoverable');
        $this->assertTrue($snap['health_flags']['recoverable_backlog'], 'recoverable_backlog flag must be set');
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

    /** @param array<string, mixed> $input */
    private function rawEnqueue(array $input): void
    {
        $packet = (new AgentControlPlaneTaskPacketBuilder)->build($input);
        (new AgentControlPlaneTaskPacketQueueRepository)->enqueue($packet);
    }

    /** @return array<string, mixed> */
    private function input(string $id, ?array $acceptance = null, ?array $evidence = null): array
    {
        return [
            'task_packet_id' => $id,
            'objective' => 'Coordination-health fixture '.$id.': implement app/Services/Ai/SelfConstruction/'.$id.'.php deterministically and prove it.',
            'operator_id' => 'tester',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'acceptance_criteria' => $acceptance ?? ['php artisan test asserts '.$id.' behaves correctly'],
            'required_evidence' => $evidence ?? ['task_packet_created'],
        ];
    }

    /** @return array<string, mixed> */
    private function testOnlyInput(string $id, ?array $contract = null): array
    {
        $input = [
            'task_packet_id' => $id,
            'objective' => 'Add a deterministic behavior characterization test for App\\Services\\Ai\\SelfConstruction\\FooGate that pins evaluate() output contract and one boundary case.',
            'operator_id' => 'tester',
            'allowed_files' => ['tests/Unit/Ai/AutonomousEvolution/Characterization/FooGateCharacterizationTest.php'],
            'scope_in' => [
                'app/Services/Ai/SelfConstruction/FooGate.php',
                'tests/Unit/Ai/AutonomousEvolution/Characterization/FooGateCharacterizationTest.php',
            ],
            'acceptance_criteria' => ['php artisan test --filter=FooGateCharacterizationTest passes'],
            'required_evidence' => ['tests_or_gates_result'],
        ];

        if ($contract !== null) {
            $input['continuation_context'] = [
                'brain_seed_credit' => [
                    'test_only_contract' => $contract,
                ],
            ];
        }

        return $input;
    }

    /** @return array<string, mixed> */
    private function forbiddenInput(string $id): array
    {
        return [
            'task_packet_id' => $id,
            'objective' => 'Forbidden-scope repair fixture '.$id.': implement app/Services/Ai/SelfConstruction/'.$id.'.php deterministically and prove it.',
            'operator_id' => 'tester',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/'.$id.'.php', 'config/atlas.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/'.$id.'.php', 'config/atlas.php'],
            'acceptance_criteria' => ['php artisan test asserts '.$id.' behaves correctly'],
            'required_evidence' => ['task_packet_created'],
        ];
    }

    /** @return array<string, mixed> */
    private function forbiddenOnlyInput(string $id): array
    {
        return [
            'task_packet_id' => $id,
            'objective' => 'repair empty forbidden scope '.$id,
            'operator_id' => 'tester',
            'allowed_files' => ['config/atlas.php'],
            'scope_in' => ['config/atlas.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['task_packet_created'],
        ];
    }
}
