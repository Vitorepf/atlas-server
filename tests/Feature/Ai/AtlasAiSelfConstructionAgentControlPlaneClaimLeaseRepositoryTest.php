<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneClaimLeaseRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_control_plane_claim_lease_runtime.v1', AgentControlPlaneClaimLeaseRepository::SCHEMA_VERSION);
        $this->assertSame('persistent_local_agent_control_plane_claim_lease_runtime', AgentControlPlaneClaimLeaseRepository::MODE);
        $this->assertSame('atlas/self-construction/agent-control-plane/leases', AgentControlPlaneClaimLeaseRepository::STORAGE_PREFIX);
        $this->assertGreaterThanOrEqual(60, AgentControlPlaneClaimLeaseRepository::MIN_TTL_SECONDS);
        $this->assertGreaterThan(AgentControlPlaneClaimLeaseRepository::MIN_TTL_SECONDS, AgentControlPlaneClaimLeaseRepository::DEFAULT_TTL_SECONDS);
        $this->assertGreaterThanOrEqual(100, AgentControlPlaneClaimLeaseRepository::MAX_REGISTRY_ENTRIES);
    }

    public function test_claim_available_task(): void
    {
        $repo = new AgentControlPlaneClaimLeaseRepository;
        $result = $repo->claim('task-claim-1', 'agent-1', $this->scope(['app/Foo.php']));
        $this->assertSame('ok', $result['status']);
        $this->assertSame('active', $result['lease_status']);
        $this->assertSame('claim_acquired', $result['event']);
        $this->assertNotEmpty($result['lease_id']);
        $this->assertNotEmpty($result['receipt']['receipt_hash']);
        $this->assertFalse($result['runtime_execution_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['ledger_write_allowed']);
    }

    public function test_double_claim_blocks(): void
    {
        $repo = new AgentControlPlaneClaimLeaseRepository;
        $repo->claim('task-double-1', 'agent-a', $this->scope(['app/Bar.php']));
        $second = $repo->claim('task-double-1', 'agent-b', $this->scope(['app/Bar.php']));
        $this->assertSame('blocked', $second['status']);
        $this->assertSame('task_already_claimed', $second['reason']);
        $this->assertSame('claim_blocked_conflict', $second['blocked_receipt']['receipt_kind']);
    }

    public function test_claim_conflict_write_set_blocks(): void
    {
        $repo = new AgentControlPlaneClaimLeaseRepository;
        $repo->claim('task-conflict-a', 'agent-a', $this->scope(['app/Shared.php', 'app/Only-A.php']));
        $b = $repo->claim('task-conflict-b', 'agent-b', $this->scope(['app/Shared.php', 'app/Only-B.php']));
        $this->assertSame('blocked', $b['status']);
        $this->assertSame('write_set_overlap', $b['reason']);
        $this->assertSame(1, count($b['conflict']));
        $this->assertContains('app/Shared.php', $b['conflict'][0]['overlap_files']);
    }

    public function test_claim_disjoint_overlap_allowed(): void
    {
        $repo = new AgentControlPlaneClaimLeaseRepository;
        $repo->claim('task-disjoint-a', 'agent-a', $this->scope(['app/A.php']));
        $b = $repo->claim('task-disjoint-b', 'agent-b', $this->scope(['app/B.php']));
        $this->assertSame('ok', $b['status']);
    }

    public function test_renew_by_owner_succeeds(): void
    {
        $repo = new AgentControlPlaneClaimLeaseRepository;
        $r = $repo->claim('task-renew-1', 'agent-1', $this->scope(['app/R.php']));
        $renew = $repo->renew($r['lease_id'], 'agent-1', 600);
        $this->assertSame('ok', $renew['status']);
        $this->assertSame('lease_renewed', $renew['event']);
        $this->assertSame(1, $renew['lease']['renew_count']);
        $this->assertSame(600, $renew['lease']['ttl_seconds']);
    }

    public function test_renew_by_non_owner_blocks(): void
    {
        $repo = new AgentControlPlaneClaimLeaseRepository;
        $r = $repo->claim('task-renew-2', 'agent-1', $this->scope(['app/R2.php']));
        $renew = $repo->renew($r['lease_id'], 'agent-z', 600);
        $this->assertSame('blocked', $renew['status']);
        $this->assertSame('not_lease_owner', $renew['reason']);
    }

    public function test_release_by_owner_succeeds(): void
    {
        $repo = new AgentControlPlaneClaimLeaseRepository;
        $r = $repo->claim('task-release-1', 'agent-1', $this->scope(['app/Rel.php']));
        $release = $repo->release($r['lease_id'], 'agent-1', ['reason' => 'completed_dry_run']);
        $this->assertSame('ok', $release['status']);
        $this->assertSame('released', $release['lease_status']);
    }

    public function test_release_by_non_owner_blocks(): void
    {
        $repo = new AgentControlPlaneClaimLeaseRepository;
        $r = $repo->claim('task-release-2', 'agent-1', $this->scope(['app/Rel2.php']));
        $release = $repo->release($r['lease_id'], 'agent-other');
        $this->assertSame('blocked', $release['status']);
        $this->assertSame('not_lease_owner', $release['reason']);
    }

    public function test_release_authorised_operator_allowed(): void
    {
        $repo = new AgentControlPlaneClaimLeaseRepository;
        $r = $repo->claim('task-release-op', 'agent-1', $this->scope(['app/Op.php']));
        $release = $repo->release($r['lease_id'], 'operator-x', [
            'operator_authorised' => true,
            'reason' => 'forced_release',
        ]);
        $this->assertSame('ok', $release['status']);
    }

    public function test_expire_leases_marks_expired(): void
    {
        $repo = new AgentControlPlaneClaimLeaseRepository;
        $r = $repo->claim('task-exp-1', 'agent-1', $this->scope(['app/E.php']), [
            'ttl_seconds' => 60,
        ]);

        // Force-expire by rewriting the file with an already-past unix timestamp.
        $disk = Storage::disk('local');
        $registryPath = AgentControlPlaneClaimLeaseRepository::REGISTRY_PATH;
        $registry = json_decode((string) $disk->get($registryPath), true, flags: JSON_THROW_ON_ERROR);
        foreach ($registry['entries'] as &$entry) {
            if ($entry['lease_id'] === $r['lease_id']) {
                $entry['expires_at_unix'] = time() - 10;
            }
        }
        unset($entry);
        $disk->put($registryPath, json_encode($registry, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $result = $repo->expireLeases();
        $this->assertSame('expired', $result['status']);
        $this->assertSame(1, $result['expired_count']);
        $this->assertContains($r['lease_id'], $result['expired_lease_ids']);
    }

    public function test_expired_lease_excluded_from_active(): void
    {
        $repo = new AgentControlPlaneClaimLeaseRepository;
        $r = $repo->claim('task-exp-2', 'agent-1', $this->scope(['app/E2.php']), ['ttl_seconds' => 60]);
        $disk = Storage::disk('local');
        $registryPath = AgentControlPlaneClaimLeaseRepository::REGISTRY_PATH;
        $registry = json_decode((string) $disk->get($registryPath), true, flags: JSON_THROW_ON_ERROR);
        foreach ($registry['entries'] as &$entry) {
            if ($entry['lease_id'] === $r['lease_id']) {
                $entry['expires_at_unix'] = time() - 5;
            }
        }
        unset($entry);
        $disk->put($registryPath, json_encode($registry, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $active = $repo->activeLeases();
        $this->assertEmpty($active);
        foreach ($active as $lease) {
            $this->assertNotSame($r['lease_id'], $lease['lease_id']);
        }
    }

    public function test_conflict_check_clear_when_no_overlap(): void
    {
        $repo = new AgentControlPlaneClaimLeaseRepository;
        $result = $repo->conflictCheck($this->scope(['app/UniqueX.php']));
        $this->assertSame('clear', $result['status']);
        $this->assertSame(0, $result['conflict_count']);
    }

    public function test_conflict_check_detects_overlap(): void
    {
        $repo = new AgentControlPlaneClaimLeaseRepository;
        $repo->claim('task-overlap-a', 'agent-a', $this->scope(['app/Overlap.php']));
        $result = $repo->conflictCheck($this->scope(['app/Overlap.php']));
        $this->assertSame('conflict', $result['status']);
        $this->assertSame(1, $result['conflict_count']);
    }

    public function test_ttl_clamped(): void
    {
        $repo = new AgentControlPlaneClaimLeaseRepository;
        $r1 = $repo->claim('task-ttl-min', 'agent-1', $this->scope(['app/T1.php']), ['ttl_seconds' => 5]);
        $this->assertSame(AgentControlPlaneClaimLeaseRepository::MIN_TTL_SECONDS, $r1['lease']['ttl_seconds']);
        $r2 = $repo->claim('task-ttl-max', 'agent-2', $this->scope(['app/T2.php']), ['ttl_seconds' => 100000]);
        $this->assertSame(AgentControlPlaneClaimLeaseRepository::MAX_TTL_SECONDS, $r2['lease']['ttl_seconds']);
    }

    public function test_runtime_flags_false(): void
    {
        $repo = new AgentControlPlaneClaimLeaseRepository;
        $flags = $repo->runtimeFlags();
        foreach ($flags as $value) {
            $this->assertFalse((bool) $value);
        }
        $r = $repo->claim('task-flags', 'agent-1', $this->scope(['app/F.php']));
        $this->assertFalse($r['lease']['runtime_execution_allowed']);
        $this->assertFalse($r['lease']['dispatch_allowed']);
        $this->assertFalse($r['lease']['provider_call_allowed']);
        $this->assertFalse($r['lease']['token_spend_allowed']);
        $this->assertFalse($r['lease']['self_programming_allowed']);
        $this->assertFalse($r['lease']['ledger_write_allowed']);
        $this->assertFalse($r['lease']['completion_real_allowed']);
    }

    public function test_lease_receipts_local(): void
    {
        $repo = new AgentControlPlaneClaimLeaseRepository;
        $r = $repo->claim('task-receipts', 'agent-1', $this->scope(['app/Rcp.php']));
        $renew = $repo->renew($r['lease_id'], 'agent-1', 200);
        $release = $repo->release($r['lease_id'], 'agent-1');
        $this->assertNotEmpty($r['lease']['receipts']);
        $this->assertNotEmpty($renew['lease']['receipts']);
        $this->assertNotEmpty($release['lease']['receipts']);
        $kinds = array_column($release['lease']['receipts'], 'receipt_kind');
        $this->assertContains(AgentControlPlaneClaimLeaseRepository::RECEIPT_CLAIM_ACQUIRED, $kinds);
        $this->assertContains(AgentControlPlaneClaimLeaseRepository::RECEIPT_LEASE_RENEWED, $kinds);
        $this->assertContains(AgentControlPlaneClaimLeaseRepository::RECEIPT_LEASE_RELEASED, $kinds);
    }

    public function test_cli_status_returns_payload(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-claim-lease-runtime-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.self_construction_agent_control_plane_claim_lease_runtime_status.v1', $payload['schema_version']);
        $this->assertSame('available', data_get($payload, 'agent_control_plane_claim_lease_runtime_status.status'));
    }

    public function test_cli_quartet_works(): void
    {
        foreach (['contract', 'preflight', 'implementation-packet'] as $stage) {
            Artisan::call('atlas:ai:self-construction', [
                "--agent-control-plane-claim-lease-runtime-{$stage}" => true,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $stageKey = str_replace('-', '_', $stage);
            $this->assertSame("atlas.self_construction_agent_control_plane_claim_lease_runtime_{$stageKey}.v1", $payload['schema_version']);
        }
    }

    public function test_lease_not_found_blocks(): void
    {
        $repo = new AgentControlPlaneClaimLeaseRepository;
        $renew = $repo->renew('lease-doesnt-exist', 'agent-1', 60);
        $this->assertSame('blocked', $renew['status']);
        $this->assertSame('lease_not_found', $renew['reason']);
        $release = $repo->release('lease-doesnt-exist', 'agent-1');
        $this->assertSame('blocked', $release['status']);
        $this->assertSame('lease_not_found', $release['reason']);
    }

    public function test_missing_inputs_blocked(): void
    {
        $repo = new AgentControlPlaneClaimLeaseRepository;
        $a = $repo->claim('', 'agent-1', $this->scope(['app/F.php']));
        $this->assertSame('blocked', $a['status']);
        $this->assertSame('task_packet_id_missing', $a['reason']);
        $b = $repo->claim('task-x', '', $this->scope(['app/F.php']));
        $this->assertSame('blocked', $b['status']);
        $this->assertSame('agent_id_missing', $b['reason']);
    }

    public function test_full_lease_payload_shape(): void
    {
        $repo = new AgentControlPlaneClaimLeaseRepository;
        $result = $repo->claim('full-shape', 'agent-x', $this->scope(['app/Full.php']));
        foreach ([
            'schema_version', 'status', 'event', 'lease_id', 'task_packet_id',
            'lease_status', 'lease', 'runtime_execution_allowed', 'dispatch_allowed',
            'ledger_write_allowed',
        ] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing $key");
        }
        $lease = $result['lease'];
        foreach ([
            'schema_version', 'lease_id', 'task_packet_id', 'agent_id', 'lease_status',
            'acquired_at', 'acquired_at_unix', 'ttl_seconds', 'expires_at', 'expires_at_unix',
            'renew_count', 'released_at', 'released_by', 'release_reason',
            'write_set', 'read_set', 'scope_lock_plan_hash', 'operator_authorisation',
            'receipts', 'history', 'runtime_execution_allowed', 'dispatch_allowed',
            'provider_call_allowed', 'token_spend_allowed', 'self_programming_allowed',
            'ledger_write_allowed', 'completion_real_allowed',
        ] as $key) {
            $this->assertArrayHasKey($key, $lease, "Missing lease $key");
        }
        $this->assertSame(0, $lease['renew_count']);
        $this->assertNull($lease['released_at']);
        $this->assertSame(AgentControlPlaneClaimLeaseRepository::LEASE_STATUS_ACTIVE, $lease['lease_status']);
    }

    public function test_active_leases_excludes_released(): void
    {
        $repo = new AgentControlPlaneClaimLeaseRepository;
        $r1 = $repo->claim('active-a', 'agent-1', $this->scope(['app/A1.php']));
        $r2 = $repo->claim('active-b', 'agent-2', $this->scope(['app/B1.php']));
        $repo->release($r2['lease_id'], 'agent-2');
        $active = $repo->activeLeases();
        $activeIds = array_column($active, 'lease_id');
        $this->assertContains($r1['lease_id'], $activeIds);
        $this->assertNotContains($r2['lease_id'], $activeIds);
    }

    public function test_active_leases_filter_by_agent(): void
    {
        $repo = new AgentControlPlaneClaimLeaseRepository;
        $repo->claim('filter-a', 'agent-1', $this->scope(['app/F1.php']));
        $repo->claim('filter-b', 'agent-2', $this->scope(['app/F2.php']));
        $only1 = $repo->activeLeases(['agent_id' => 'agent-1']);
        $this->assertCount(1, $only1);
        $this->assertSame('agent-1', $only1[0]['agent_id']);
    }

    public function test_registry_compaction_keeps_active_index_entries_and_preserves_lease_files(): void
    {
        $disk = Storage::disk('local');
        $entries = [];
        for ($i = 0; $i < AgentControlPlaneClaimLeaseRepository::MAX_REGISTRY_ENTRIES + 25; $i++) {
            $entries[] = [
                'lease_id' => "lease_old_{$i}",
                'task_packet_id' => "old-task-{$i}",
                'agent_id' => 'agent-old',
                'lease_status' => AgentControlPlaneClaimLeaseRepository::LEASE_STATUS_RELEASED,
                'acquired_at' => now()->subMinutes($i + 1)->toIso8601String(),
                'expires_at' => now()->subMinutes($i)->toIso8601String(),
                'expires_at_unix' => time() - $i,
                'write_set' => ["app/Old{$i}.php"],
                'read_set' => [],
            ];
        }
        $disk->put(
            AgentControlPlaneClaimLeaseRepository::REGISTRY_PATH,
            json_encode(['entries' => $entries], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        );

        $repo = new AgentControlPlaneClaimLeaseRepository;
        $claimed = $repo->claim('compact-active-task', 'agent-active', $this->scope(['app/Active.php']));

        $registry = json_decode((string) $disk->get(AgentControlPlaneClaimLeaseRepository::REGISTRY_PATH), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('ok', $claimed['status']);
        $this->assertTrue((bool) ($registry['compacted'] ?? false));
        $this->assertLessThanOrEqual(AgentControlPlaneClaimLeaseRepository::MAX_REGISTRY_ENTRIES, count($registry['entries']));
        $this->assertTrue(collect($registry['entries'])->contains(
            fn (array $entry): bool => (string) $entry['lease_id'] === (string) $claimed['lease_id']
                && (string) $entry['lease_status'] === AgentControlPlaneClaimLeaseRepository::LEASE_STATUS_ACTIVE,
        ));
        $this->assertTrue($disk->exists(AgentControlPlaneClaimLeaseRepository::STORAGE_PREFIX.'/'.$claimed['lease_id'].'.json'));
    }

    public function test_constants_receipt_kinds_canonical(): void
    {
        $this->assertSame('claim_acquired', AgentControlPlaneClaimLeaseRepository::RECEIPT_CLAIM_ACQUIRED);
        $this->assertSame('lease_renewed', AgentControlPlaneClaimLeaseRepository::RECEIPT_LEASE_RENEWED);
        $this->assertSame('lease_released', AgentControlPlaneClaimLeaseRepository::RECEIPT_LEASE_RELEASED);
        $this->assertSame('lease_expired', AgentControlPlaneClaimLeaseRepository::RECEIPT_LEASE_EXPIRED);
        $this->assertSame('claim_blocked_conflict', AgentControlPlaneClaimLeaseRepository::RECEIPT_CLAIM_BLOCKED_CONFLICT);
    }

    /**
     * @param  list<string>  $writeSet
     * @return array<string, mixed>
     */
    private function scope(array $writeSet): array
    {
        return [
            'write_set' => $writeSet,
            'read_set' => $writeSet,
            'scope_lock_plan_hash' => 'test_scope_lock',
        ];
    }
}
