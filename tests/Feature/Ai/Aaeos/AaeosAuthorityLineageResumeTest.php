<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneClaimLeaseRepository;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * P2c: authority lineage packet→lease without remint; revoke sticks; detach ≠ cancel.
 */
final class AaeosAuthorityLineageResumeTest extends TestCase
{
    private AgentControlPlaneClaimLeaseRepository $leases;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->leases = new AgentControlPlaneClaimLeaseRepository('local');
    }

    public function test_claim_binds_authority_lineage_and_renew_does_not_remint(): void
    {
        $claim = $this->leases->claim('packet-p2c-1', 'worker-a', $this->scope(['app/Foo.php']), [
            'ttl_seconds' => 120,
            'authority_lineage' => [
                'authority_ref' => 'mandate-p2c-1',
                'authority_hash' => str_repeat('ab', 32),
                'authority_revision' => 3,
            ],
        ]);
        $this->assertSame('ok', $claim['status']);
        $this->assertSame('claim_acquired', $claim['event']);
        $lease = $claim['lease'];
        $nonce = (string) $lease['authority_nonce'];
        $this->assertNotSame('', $nonce);
        $this->assertSame('mandate-p2c-1', $lease['authority_lineage']['authority_ref']);
        $this->assertSame(3, (int) $lease['authority_lineage']['authority_revision']);

        $renew = $this->leases->renew((string) $lease['lease_id'], 'worker-a', 180);
        $this->assertSame('ok', $renew['status']);
        $this->assertSame('lease_renewed', $renew['event']);
        $this->assertSame($nonce, (string) $renew['lease']['authority_nonce'], 'authority_nonce must not remint on renew');
        $this->assertSame(3, (int) $renew['lease']['authority_lineage']['authority_revision']);
        $this->assertFalse((bool) $renew['lease']['authority_revoked']);
    }

    public function test_revocation_blocks_renew_and_pre_effect_revalidation(): void
    {
        $claim = $this->leases->claim('packet-p2c-2', 'worker-b', $this->scope(['app/Bar.php']), [
            'ttl_seconds' => 120,
            'authority_lineage' => [
                'authority_ref' => 'mandate-p2c-2',
                'authority_hash' => str_repeat('cd', 32),
                'authority_revision' => 1,
            ],
        ]);
        $this->assertSame('ok', $claim['status']);
        $leaseId = (string) $claim['lease_id'];

        $release = $this->leases->release($leaseId, 'worker-b', ['reason' => 'revoked_for_test']);
        $this->assertSame('ok', $release['status']);
        $this->assertTrue((bool) $release['authority_revoked']);

        $renew = $this->leases->renew($leaseId, 'worker-b', 120);
        $this->assertSame('blocked', $renew['status']);
        $this->assertTrue(
            in_array($renew['reason'] ?? '', ['authority_revoked', 'lease_not_active'], true),
            json_encode($renew),
        );

        $claim2 = $this->leases->claim('packet-p2c-3', 'worker-c', $this->scope(['app/Baz.php']), [
            'ttl_seconds' => 120,
            'authority_lineage' => [
                'authority_ref' => 'mandate-p2c-3',
                'authority_hash' => str_repeat('ef', 32),
                'authority_revision' => 2,
            ],
        ]);
        $lease2Id = (string) $claim2['lease_id'];
        $this->leases->release($lease2Id, 'worker-c');
        $reval = $this->leases->revalidateForPreEffect($lease2Id, 'worker-c');
        $this->assertSame('blocked', $reval['status']);
        $this->assertTrue(
            in_array($reval['reason'] ?? '', ['authority_revoked', 'lease_not_active'], true),
            json_encode($reval),
        );
    }

    public function test_client_detach_is_not_cancel_and_pre_effect_still_ok(): void
    {
        $claim = $this->leases->claim('packet-p2c-detach', 'worker-d', $this->scope(['app/Detach.php']), [
            'ttl_seconds' => 300,
            'authority_lineage' => [
                'authority_ref' => 'mandate-detach',
                'authority_hash' => str_repeat('11', 32),
                'authority_revision' => 1,
            ],
        ]);
        $leaseId = (string) $claim['lease_id'];

        $detach = $this->leases->markClientDetached($leaseId, 'worker-d');
        $this->assertSame('ok', $detach['status']);
        $this->assertTrue((bool) $detach['lease']['client_detached']);
        $this->assertSame(AgentControlPlaneClaimLeaseRepository::LEASE_STATUS_ACTIVE, $detach['lease_status']);
        $this->assertFalse((bool) $detach['authority_revoked']);

        $reval = $this->leases->revalidateForPreEffect($leaseId, 'worker-d');
        $this->assertSame('ok', $reval['status']);
        $this->assertSame('lease_revalidated', $reval['event']);
    }

    public function test_claim_with_partial_authority_lineage_fails_closed(): void
    {
        $result = $this->leases->claim('packet-p2c-bad', 'worker-e', $this->scope(['app/Bad.php']), [
            'authority_ref' => 'only-ref',
        ]);
        $this->assertSame('blocked', $result['status']);
        $this->assertSame('authority_lineage_required', $result['reason']);
    }

    public function test_pre_effect_revalidation_ok_for_bound_active_lease(): void
    {
        $claim = $this->leases->claim('packet-p2c-ok', 'worker-f', $this->scope(['app/Ok.php']), [
            'ttl_seconds' => 300,
            'authority_lineage' => [
                'authority_ref' => 'mandate-ok',
                'authority_hash' => str_repeat('22', 32),
                'authority_revision' => 5,
            ],
        ]);
        $reval = $this->leases->revalidateForPreEffect((string) $claim['lease_id'], 'worker-f');
        $this->assertSame('ok', $reval['status']);
        $this->assertSame(5, (int) data_get($reval, 'receipt.authority_revision'));
    }

    /** @param list<string> $write @return array<string,mixed> */
    private function scope(array $write): array
    {
        return [
            'write_set' => $write,
            'read_set' => [],
            'scope_lock_plan_hash' => hash('sha256', implode('|', $write)),
        ];
    }
}
