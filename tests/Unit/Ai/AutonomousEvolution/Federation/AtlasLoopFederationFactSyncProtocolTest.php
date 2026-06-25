<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Federation;

use App\Services\Ai\AutonomousEvolution\Federation\AtlasLoopFederationFactSyncProtocol;
use App\Services\Ai\AutonomousEvolution\Federation\AtlasLoopFederationPeerRegistry;
use App\Services\Ai\AutonomousEvolution\Federation\FederationFactRejectedException;
use Tests\TestCase;

class AtlasLoopFederationFactSyncProtocolTest extends TestCase
{
    private string $peersPath = '';

    private string $outboxPath = '';

    private string $seenSetPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $tag = bin2hex(random_bytes(4));
        $base = sys_get_temp_dir().'/atlas-federation-protocol-'.$tag;
        @mkdir($base, 0o755, true);
        $this->peersPath = $base.'/peers.json';
        $this->outboxPath = $base.'/outbox.ndjson';
        $this->seenSetPath = $base.'/seen.txt';
    }

    protected function tearDown(): void
    {
        foreach ([$this->peersPath, $this->outboxPath, $this->seenSetPath] as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function registry(): AtlasLoopFederationPeerRegistry
    {
        return new AtlasLoopFederationPeerRegistry($this->peersPath, static fn (): string => '2026-06-25T00:00:00Z');
    }

    public function test_publish_emits_envelope_with_monotonic_seq_and_stable_content_hash(): void
    {
        $registry = $this->registry();
        $registry->register(['peer_id' => 'peer-A', 'scope' => 's', 'endpoint' => 'file:///tmp/a', 'capability_axes' => ['fact.x']]);

        $protocol = new AtlasLoopFederationFactSyncProtocol($registry, $this->outboxPath, $this->seenSetPath);
        $a = $protocol->publish('peer-A', ['fact_id' => 'f1', 'fact_kind' => 'fact.x', 'payload' => ['k' => 'v']]);
        $b = $protocol->publish('peer-A', ['fact_id' => 'f2', 'fact_kind' => 'fact.x', 'payload' => ['k' => 'v']]);

        self::assertSame(1, $a['seq']);
        self::assertSame(2, $b['seq']);
        self::assertSame($a['content_hash'], $b['content_hash'], 'identical payload yields identical content_hash');
    }

    public function test_subscribe_rejects_envelope_from_unregistered_peer(): void
    {
        $protocol = new AtlasLoopFederationFactSyncProtocol($this->registry(), $this->outboxPath, $this->seenSetPath);

        $this->expectException(FederationFactRejectedException::class);
        $protocol->subscribe([
            'peer_id' => 'unknown-peer',
            'fact_kind' => 'fact.x',
            'fact_id' => 'f1',
            'seq' => 1,
            'content_hash' => 'whatever',
            'observed_at' => '2026-06-25T00:00:00Z',
            'payload' => ['k' => 'v'],
        ]);
    }

    public function test_subscribe_rejects_envelope_with_content_hash_mismatch(): void
    {
        $registry = $this->registry();
        $registry->register(['peer_id' => 'peer-A', 'scope' => 's', 'endpoint' => 'e', 'capability_axes' => []]);
        $protocol = new AtlasLoopFederationFactSyncProtocol($registry, $this->outboxPath, $this->seenSetPath);

        $this->expectException(FederationFactRejectedException::class);
        $protocol->subscribe([
            'peer_id' => 'peer-A',
            'fact_kind' => 'fact.x',
            'fact_id' => 'f1',
            'seq' => 1,
            'content_hash' => 'tampered-hash',
            'observed_at' => '2026-06-25T00:00:00Z',
            'payload' => ['k' => 'v'],
        ]);
    }

    public function test_subscribe_replay_of_seen_fact_id_is_idempotent_noop(): void
    {
        $registry = $this->registry();
        $registry->register(['peer_id' => 'peer-A', 'scope' => 's', 'endpoint' => 'e', 'capability_axes' => []]);
        $protocol = new AtlasLoopFederationFactSyncProtocol($registry, $this->outboxPath, $this->seenSetPath);

        $envelope = $protocol->publish('peer-A', ['fact_id' => 'f1', 'fact_kind' => 'fact.x', 'payload' => ['k' => 'v']]);
        $first = $protocol->subscribe($envelope);
        $second = $protocol->subscribe($envelope);

        self::assertTrue($first['accepted']);
        self::assertTrue($second['accepted']);
        self::assertSame('idempotent_replay', $second['reason']);
    }

    public function test_publish_to_unregistered_peer_is_rejected(): void
    {
        $protocol = new AtlasLoopFederationFactSyncProtocol($this->registry(), $this->outboxPath, $this->seenSetPath);

        $this->expectException(FederationFactRejectedException::class);
        $protocol->publish('unknown-peer', ['fact_id' => 'f1', 'fact_kind' => 'fact.x', 'payload' => []]);
    }

    public function test_subscribe_records_seen_only_after_validation(): void
    {
        $registry = $this->registry();
        $registry->register(['peer_id' => 'peer-A', 'scope' => 's', 'endpoint' => 'e', 'capability_axes' => []]);
        $protocol = new AtlasLoopFederationFactSyncProtocol($registry, $this->outboxPath, $this->seenSetPath);

        try {
            $protocol->subscribe([
                'peer_id' => 'peer-A',
                'fact_kind' => 'fact.x',
                'fact_id' => 'f1',
                'seq' => 1,
                'content_hash' => 'tampered',
                'observed_at' => '2026-06-25T00:00:00Z',
                'payload' => ['k' => 'v'],
            ]);
        } catch (FederationFactRejectedException) {
            // expected
        }

        // No seen-set state should exist.
        self::assertFalse(is_file($this->seenSetPath));
    }
}
