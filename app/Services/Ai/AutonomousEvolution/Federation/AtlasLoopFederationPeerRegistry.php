<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Federation;

use RuntimeException;

/**
 * Canonical, byte-stable LOCAL registry of peer Loops in the federation.
 *
 * Each peer carries: peer_id, scope, endpoint, capability_axes[], last_seen_utc, trust_tier.
 *
 * Operations:
 *   - register(peer)         — idempotent on (peer_id, scope, endpoint); throws on conflict.
 *   - touch(peer_id)         — bumps last_seen_utc.
 *   - forget(peer_id)        — removes the peer.
 *   - list() / get(peer_id)  — read.
 *   - capabilityMatch(axis)  — only peers whose capability_axes include the axis.
 *   - snapshot()             — returns the on-disk JSON read-model byte-identically.
 *
 * Unknown peer_ids are treated as untrusted; trust is never auto-promoted.
 */
final class AtlasLoopFederationPeerRegistry
{
    public const SCHEMA = 'atlas.loop.federation_peer_registry.v1';

    public const TRUST_UNKNOWN = 'unknown';

    public const TRUST_OBSERVED = 'observed';

    public const TRUST_OPERATOR_TRUSTED = 'operator_trusted';

    /** @var callable(): string */
    private $clock;

    public function __construct(
        private readonly string $snapshotPath,
        ?callable $clock = null,
    ) {
        $dir = dirname($this->snapshotPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
        $this->clock = $clock ?? static fn (): string => gmdate('Y-m-d\TH:i:s\Z');
    }

    /**
     * @param  array<string,mixed>  $peer  {peer_id, scope, endpoint, capability_axes[], trust_tier?}
     * @return array<string,mixed>
     */
    public function register(array $peer): array
    {
        $peerId = (string) ($peer['peer_id'] ?? '');
        if ($peerId === '') {
            throw new FederationPeerConflictException('peer_id_required');
        }
        $scope = (string) ($peer['scope'] ?? '');
        $endpoint = (string) ($peer['endpoint'] ?? '');
        $axes = array_values(array_map('strval', (array) ($peer['capability_axes'] ?? [])));
        sort($axes, SORT_STRING);
        $axes = array_values(array_unique($axes));
        $trustTier = (string) ($peer['trust_tier'] ?? self::TRUST_UNKNOWN);

        $snapshot = $this->loadSnapshot();
        if (isset($snapshot['peers'][$peerId])) {
            $existing = $snapshot['peers'][$peerId];
            if ((string) $existing['scope'] === $scope && (string) $existing['endpoint'] === $endpoint) {
                // Idempotent on (scope, endpoint). Touch and return.
                $snapshot['peers'][$peerId]['last_seen_utc'] = ($this->clock)();
                $this->writeSnapshot($snapshot);

                return $snapshot['peers'][$peerId];
            }
            throw new FederationPeerConflictException(sprintf(
                'peer_id_conflict: %s already registered with scope=%s endpoint=%s — refusing new scope=%s endpoint=%s',
                $peerId,
                (string) $existing['scope'],
                (string) $existing['endpoint'],
                $scope,
                $endpoint,
            ));
        }

        $row = [
            'peer_id' => $peerId,
            'scope' => $scope,
            'endpoint' => $endpoint,
            'capability_axes' => $axes,
            'last_seen_utc' => ($this->clock)(),
            'trust_tier' => $trustTier,
        ];
        $snapshot['peers'][$peerId] = $row;
        $this->writeSnapshot($snapshot);

        return $row;
    }

    public function touch(string $peerId): bool
    {
        $snapshot = $this->loadSnapshot();
        if (! isset($snapshot['peers'][$peerId])) {
            return false;
        }
        $snapshot['peers'][$peerId]['last_seen_utc'] = ($this->clock)();
        $this->writeSnapshot($snapshot);

        return true;
    }

    public function forget(string $peerId): bool
    {
        $snapshot = $this->loadSnapshot();
        if (! isset($snapshot['peers'][$peerId])) {
            return false;
        }
        unset($snapshot['peers'][$peerId]);
        $this->writeSnapshot($snapshot);

        return true;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function list(): array
    {
        $snapshot = $this->loadSnapshot();
        $peers = array_values($snapshot['peers'] ?? []);
        usort($peers, static fn (array $a, array $b): int => strcmp((string) $a['peer_id'], (string) $b['peer_id']));

        return $peers;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function get(string $peerId): ?array
    {
        $snapshot = $this->loadSnapshot();

        return $snapshot['peers'][$peerId] ?? null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function capabilityMatch(string $axis): array
    {
        return array_values(array_filter(
            $this->list(),
            static fn (array $p): bool => in_array($axis, (array) ($p['capability_axes'] ?? []), true),
        ));
    }

    /**
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        return $this->loadSnapshot();
    }

    /**
     * @return array<string,mixed>
     */
    private function loadSnapshot(): array
    {
        if (! is_file($this->snapshotPath)) {
            return ['schema' => self::SCHEMA, 'peers' => []];
        }
        $decoded = json_decode((string) file_get_contents($this->snapshotPath), true);
        if (! is_array($decoded)) {
            return ['schema' => self::SCHEMA, 'peers' => []];
        }
        if (! isset($decoded['peers']) || ! is_array($decoded['peers'])) {
            $decoded['peers'] = [];
        }

        return $decoded;
    }

    /**
     * @param  array<string,mixed>  $snapshot
     */
    private function writeSnapshot(array $snapshot): void
    {
        $snapshot['schema'] = self::SCHEMA;
        // Byte-stable: sort peers by key so identical state ⇒ identical bytes.
        $peers = (array) ($snapshot['peers'] ?? []);
        ksort($peers, SORT_STRING);
        $snapshot['peers'] = $peers;

        $bytes = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($bytes === false) {
            throw new RuntimeException('AtlasLoopFederationPeerRegistry: json_encode failed');
        }
        if (file_put_contents($this->snapshotPath, $bytes) === false) {
            throw new RuntimeException('AtlasLoopFederationPeerRegistry: cannot write '.$this->snapshotPath);
        }
    }
}

final class FederationPeerConflictException extends RuntimeException
{
}
