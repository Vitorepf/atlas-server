<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AgentControlPlaneClaimLeaseRepositoryReclaimTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_reclaim_expired_leases_returns_task_rich_reclaim_payload_and_receipts(): void
    {
        $repo = new AgentControlPlaneClaimLeaseRepository;
        $first = $repo->claim('task-reclaim-a', 'agent-a', $this->scope(['app/ReclaimA.php']), [
            'ttl_seconds' => 60,
        ]);
        $second = $repo->claim('task-reclaim-b', 'agent-b', $this->scope(['app/ReclaimB.php']), [
            'ttl_seconds' => 60,
        ]);
        $firstExpiresAt = time() - 20;
        $secondExpiresAt = time() - 10;

        $this->forceRegistryExpirations([
            (string) $first['lease_id'] => $firstExpiresAt,
            (string) $second['lease_id'] => $secondExpiresAt,
        ]);

        $result = $repo->reclaimExpiredLeases();

        $this->assertSame('ok', $result['status']);
        $this->assertSame(2, $result['expired_count']);
        $this->assertSame([$first['lease_id'], $second['lease_id']], $result['expired_lease_ids']);
        $this->assertSame(['task-reclaim-a', 'task-reclaim-b'], $result['reclaimable_tasks']);
        $this->assertSame([
            [
                'lease_id' => $first['lease_id'],
                'task_packet_id' => 'task-reclaim-a',
                'agent_id' => 'agent-a',
                'expires_at_unix' => $firstExpiresAt,
            ],
            [
                'lease_id' => $second['lease_id'],
                'task_packet_id' => 'task-reclaim-b',
                'agent_id' => 'agent-b',
                'expires_at_unix' => $secondExpiresAt,
            ],
        ], $result['expired_leases']);
        $this->assertFalse($result['runtime_execution_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['ledger_write_allowed']);

        $firstLease = $repo->get((string) $first['lease_id']);
        $this->assertNotNull($firstLease);
        $this->assertSame(AgentControlPlaneClaimLeaseRepository::LEASE_STATUS_EXPIRED, $firstLease['lease_status']);
        $receipt = $this->receiptOfKind($firstLease, AgentControlPlaneClaimLeaseRepository::RECEIPT_LEASE_EXPIRED);
        $this->assertSame('task-reclaim-a', $receipt['task_packet_id']);
        $this->assertSame('agent-a', $receipt['agent_id']);
        $this->assertSame($first['lease_id'], $receipt['lease_id']);
        $this->assertTrue($receipt['reclaim_eligible']);

        $registry = $this->registry();
        $firstEntry = collect($registry['entries'])->firstWhere('lease_id', $first['lease_id']);
        $secondEntry = collect($registry['entries'])->firstWhere('lease_id', $second['lease_id']);
        $this->assertSame(AgentControlPlaneClaimLeaseRepository::LEASE_STATUS_EXPIRED, $firstEntry['lease_status']);
        $this->assertSame(AgentControlPlaneClaimLeaseRepository::LEASE_STATUS_EXPIRED, $secondEntry['lease_status']);
    }

    public function test_reclaim_expired_leases_is_idempotent_after_first_transition(): void
    {
        $repo = new AgentControlPlaneClaimLeaseRepository;
        $claim = $repo->claim('task-reclaim-idempotent', 'agent-a', $this->scope(['app/ReclaimOnce.php']), [
            'ttl_seconds' => 60,
        ]);

        $this->forceRegistryExpirations([
            (string) $claim['lease_id'] => time() - 20,
        ]);

        $first = $repo->reclaimExpiredLeases();
        $second = $repo->reclaimExpiredLeases();

        $this->assertSame('ok', $first['status']);
        $this->assertSame('no_expirations', $second['status']);
        $this->assertSame(0, $second['expired_count']);
        $this->assertSame([], $second['expired_lease_ids']);
        $this->assertSame([], $second['expired_leases']);
        $this->assertSame([], $second['reclaimable_tasks']);

        $lease = $repo->get((string) $claim['lease_id']);
        $this->assertNotNull($lease);
        $expiredReceipts = array_values(array_filter(
            (array) $lease['receipts'],
            static fn (array $receipt): bool => (string) ($receipt['receipt_kind'] ?? '') === AgentControlPlaneClaimLeaseRepository::RECEIPT_LEASE_EXPIRED,
        ));
        $this->assertCount(1, $expiredReceipts);
        $this->assertTrue($expiredReceipts[0]['reclaim_eligible']);
    }

    /**
     * @param  array<string, int>  $expirationsByLeaseId
     */
    private function forceRegistryExpirations(array $expirationsByLeaseId): void
    {
        $disk = Storage::disk('local');
        $registry = $this->registry();

        foreach ($registry['entries'] as &$entry) {
            $leaseId = (string) ($entry['lease_id'] ?? '');
            if (array_key_exists($leaseId, $expirationsByLeaseId)) {
                $entry['expires_at_unix'] = $expirationsByLeaseId[$leaseId];
            }
        }
        unset($entry);

        $disk->put(
            AgentControlPlaneClaimLeaseRepository::REGISTRY_PATH,
            json_encode($registry, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function registry(): array
    {
        return json_decode(
            (string) Storage::disk('local')->get(AgentControlPlaneClaimLeaseRepository::REGISTRY_PATH),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param  array<string, mixed>  $lease
     * @return array<string, mixed>
     */
    private function receiptOfKind(array $lease, string $kind): array
    {
        foreach ((array) ($lease['receipts'] ?? []) as $receipt) {
            if ((string) ($receipt['receipt_kind'] ?? '') === $kind) {
                return $receipt;
            }
        }

        $this->fail("Receipt {$kind} not found.");
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
