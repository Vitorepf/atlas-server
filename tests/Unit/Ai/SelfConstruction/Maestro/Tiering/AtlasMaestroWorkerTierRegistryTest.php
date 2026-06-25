<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Tiering;

use App\Services\Ai\SelfConstruction\Maestro\Tiering\AtlasMaestroWorkerTierRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves the Maestro worker-tier registry: register/lookup roundtrip preserves declared_max_tier + meta;
 * snapshot is deterministically ordered by client_id; revoke is atomic (lookup returns null after); an
 * invalid declaredMaxTier throws and never persists.
 */
final class AtlasMaestroWorkerTierRegistryTest extends TestCase
{
    private string $snapshotPath;

    private AtlasMaestroWorkerTierRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotPath = sys_get_temp_dir().'/atlas_maestro_tier_'.bin2hex(random_bytes(6)).'.json';
        $this->registry = new AtlasMaestroWorkerTierRegistry($this->snapshotPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->snapshotPath);
        parent::tearDown();
    }

    public function test_register_then_lookup_returns_record_with_declared_tier_and_meta_verbatim(): void
    {
        $rec = $this->registry->register('sonnet-cli-1', 'easy', ['model' => 'sonnet-4', 'rps' => 5]);
        $this->assertSame('sonnet-cli-1', $rec->clientId);
        $this->assertSame('easy', $rec->declaredMaxTier);

        $hit = $this->registry->lookup('sonnet-cli-1');
        $this->assertNotNull($hit);
        $this->assertSame('easy', $hit->declaredMaxTier);
        $this->assertSame(['model' => 'sonnet-4', 'rps' => 5], $hit->meta);
    }

    public function test_two_clients_produce_snapshot_ordered_by_client_id(): void
    {
        $this->registry->register('zeta', 'hard');
        $this->registry->register('alpha', 'easy');
        $this->registry->register('mu', 'hardest');

        $raw = file_get_contents($this->snapshotPath);
        $decoded = json_decode($raw, true);
        $ids = array_column($decoded, 'client_id');
        $this->assertSame(['alpha', 'mu', 'zeta'], $ids, 'snapshot ordered ascending by client_id');
    }

    public function test_byte_identical_snapshot_for_same_logical_state_regardless_of_registration_order(): void
    {
        $this->registry->register('b', 'hard');
        $this->registry->register('a', 'easy');
        $snapA = file_get_contents($this->snapshotPath);
        @unlink($this->snapshotPath);

        $reg2 = new AtlasMaestroWorkerTierRegistry($this->snapshotPath);
        $reg2->register('a', 'easy');
        $reg2->register('b', 'hard');
        $snapB = file_get_contents($this->snapshotPath);

        $this->assertSame($snapA, $snapB, 'snapshot is order-independent for the same logical state');
    }

    public function test_revoke_removes_the_client_and_rewrites_atomically(): void
    {
        $this->registry->register('a', 'easy');
        $this->registry->register('b', 'hardest');
        $this->assertTrue($this->registry->revoke('a'));
        $this->assertNull($this->registry->lookup('a'));
        $this->assertNotNull($this->registry->lookup('b'));

        // Atomic: no leftover temp files in the snapshot dir.
        $glob = glob(dirname($this->snapshotPath).'/'.basename($this->snapshotPath).'.tmp.*');
        $this->assertSame([], $glob ?: [], 'no leftover .tmp.* file from atomic rename');
    }

    public function test_invalid_tier_throws_and_does_not_persist(): void
    {
        try {
            $this->registry->register('a', 'opus-supreme');
            $this->fail('expected exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('declaredMaxTier outside allowed set', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->snapshotPath, 'bad input must NOT write a snapshot');
    }

    public function test_list_returns_all_registered_clients(): void
    {
        $this->registry->register('a', 'easy');
        $this->registry->register('b', 'hard');
        $this->registry->register('c', 'hardest');
        $rows = $this->registry->list();
        $this->assertCount(3, $rows);
        $ids = array_map(static fn ($r): string => $r->clientId, $rows);
        sort($ids);
        $this->assertSame(['a', 'b', 'c'], $ids);
    }

    public function test_revoking_unknown_client_returns_false(): void
    {
        $this->assertFalse($this->registry->revoke('ghost'));
    }
}
