<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Federation;

use App\Services\Ai\AutonomousEvolution\Federation\AtlasLoopFederationPeerRegistry;
use App\Services\Ai\AutonomousEvolution\Federation\FederationPeerConflictException;
use Tests\TestCase;

class AtlasLoopFederationPeerRegistryTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-federation-peers-'.bin2hex(random_bytes(6)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function registry(): AtlasLoopFederationPeerRegistry
    {
        return new AtlasLoopFederationPeerRegistry($this->path, static fn (): string => '2026-06-25T00:00:00Z');
    }

    public function test_register_idempotent_on_same_scope_endpoint(): void
    {
        $registry = $this->registry();
        $peer = ['peer_id' => 'p1', 'scope' => 'atlas-server', 'endpoint' => 'file:///tmp/a', 'capability_axes' => ['fact.merge_certified']];

        $a = $registry->register($peer);
        $b = $registry->register($peer);

        self::assertSame($a['peer_id'], $b['peer_id']);
        self::assertCount(1, $registry->list());
    }

    public function test_register_conflicting_endpoint_throws(): void
    {
        $registry = $this->registry();
        $registry->register(['peer_id' => 'p1', 'scope' => 'atlas-server', 'endpoint' => 'file:///tmp/a', 'capability_axes' => []]);

        $this->expectException(FederationPeerConflictException::class);
        $registry->register(['peer_id' => 'p1', 'scope' => 'atlas-server', 'endpoint' => 'file:///tmp/b', 'capability_axes' => []]);
    }

    public function test_list_returns_peers_in_deterministic_order_by_peer_id(): void
    {
        $registry = $this->registry();
        $registry->register(['peer_id' => 'zeta', 'scope' => 's', 'endpoint' => 'e', 'capability_axes' => []]);
        $registry->register(['peer_id' => 'alpha', 'scope' => 's', 'endpoint' => 'e', 'capability_axes' => []]);
        $registry->register(['peer_id' => 'mu', 'scope' => 's', 'endpoint' => 'e', 'capability_axes' => []]);

        $ids = array_column($registry->list(), 'peer_id');
        self::assertSame(['alpha', 'mu', 'zeta'], $ids);
    }

    public function test_capability_match_filters_by_axis(): void
    {
        $registry = $this->registry();
        $registry->register(['peer_id' => 'p1', 'scope' => 's', 'endpoint' => 'e1', 'capability_axes' => ['fact.merge_certified', 'fact.replenisher']]);
        $registry->register(['peer_id' => 'p2', 'scope' => 's', 'endpoint' => 'e2', 'capability_axes' => ['fact.replenisher']]);
        $registry->register(['peer_id' => 'p3', 'scope' => 's', 'endpoint' => 'e3', 'capability_axes' => []]);

        $matches = $registry->capabilityMatch('fact.merge_certified');
        self::assertCount(1, $matches);
        self::assertSame('p1', $matches[0]['peer_id']);
    }

    public function test_snapshot_is_byte_identical_across_two_reads_with_no_state_change(): void
    {
        $registry = $this->registry();
        $registry->register(['peer_id' => 'p1', 'scope' => 's', 'endpoint' => 'e1', 'capability_axes' => ['x']]);

        $bytesA = (string) file_get_contents($this->path);
        $bytesB = (string) file_get_contents($this->path);
        self::assertSame($bytesA, $bytesB);
    }

    public function test_forget_removes_peer(): void
    {
        $registry = $this->registry();
        $registry->register(['peer_id' => 'p1', 'scope' => 's', 'endpoint' => 'e', 'capability_axes' => []]);
        self::assertNotNull($registry->get('p1'));

        self::assertTrue($registry->forget('p1'));
        self::assertNull($registry->get('p1'));
    }

    public function test_touch_returns_false_for_unknown_peer(): void
    {
        self::assertFalse($this->registry()->touch('never-registered'));
    }
}
