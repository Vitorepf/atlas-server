<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Federation;

use Throwable;

/**
 * Fail-closed firewall for the federation ingest path.
 *
 * Guarantees that ONE peer Loop's misbehavior (malformed envelope, schema violation, content_hash
 * mismatch, replay storm, clock skew beyond bound, exception during ingest) NEVER:
 *   - breaks the local Loop,
 *   - contaminates the local FACT stream,
 *   - bubbles up as an exception (the local Loop must continue even if every peer misbehaves).
 *
 * Auto-degrades a peer's trust-tier after K violations in a sliding window.
 *
 * NOT a vote / score / verdict. Purely a quarantine + tier-decay mechanism. The local Loop's
 * behavior with all peers degraded must be byte-identical to a non-federated Loop.
 *
 * Quarantine bucket shape (per-peer):
 *   {
 *     peer_id => [ { envelope_hash, reason, observed_at }, ... ]
 *   }
 *
 * Tier decay: when a peer accumulates ≥ violationThreshold violations within
 * slidingWindowSeconds, the peer is moved from 'trusted' → 'observed' → 'quarantined'.
 */
final class AtlasLoopFederationIsolationGuard
{
    public const SCHEMA = 'atlas.loop.federation_isolation_guard.v1';

    public const TIER_TRUSTED = 'trusted';

    public const TIER_OBSERVED = 'observed';

    public const TIER_QUARANTINED = 'quarantined';

    public const REASON_MALFORMED_ENVELOPE = 'malformed_envelope';

    public const REASON_SCHEMA_VIOLATION = 'schema_violation';

    public const REASON_CONTENT_HASH_MISMATCH = 'content_hash_mismatch';

    public const REASON_REPLAY = 'replay_storm';

    public const REASON_CLOCK_SKEW = 'clock_skew_beyond_bound';

    public const REASON_INGEST_EXCEPTION = 'ingest_exception';

    /** @var array<string, list<array<string,string>>> */
    private array $quarantine = [];

    /** @var array<string, list<int>> peer_id → timestamps of recent violations */
    private array $violationWindow = [];

    /** @var array<string, string> peer_id → current tier */
    private array $peerTiers = [];

    public function __construct(
        private readonly int $violationThreshold = 3,
        private readonly int $slidingWindowSeconds = 300,
        private readonly int $clockSkewBoundSeconds = 600,
    ) {}

    /**
     * Ingest one envelope through the firewall.
     *
     * @param  array<string,mixed>  $envelope
     * @return array{accepted:bool, peer_id:string, fact:?array<string,mixed>, reason:?string}
     */
    public function ingest(array $envelope, string $nowIso): array
    {
        try {
            return $this->ingestUnsafe($envelope, $nowIso);
        } catch (Throwable $e) {
            $peerId = (string) ($envelope['peer_id'] ?? 'unknown');
            $this->quarantineRow($peerId, '', self::REASON_INGEST_EXCEPTION.':'.$e->getMessage(), $nowIso);
            $this->recordViolation($peerId, $nowIso);

            return ['accepted' => false, 'peer_id' => $peerId, 'fact' => null, 'reason' => self::REASON_INGEST_EXCEPTION];
        }
    }

    /**
     * @param  array<string,mixed>  $envelope
     * @return array{accepted:bool, peer_id:string, fact:?array<string,mixed>, reason:?string}
     */
    private function ingestUnsafe(array $envelope, string $nowIso): array
    {
        $peerId = (string) ($envelope['peer_id'] ?? '');
        if ($peerId === '') {
            $this->quarantineRow('unknown', '', self::REASON_MALFORMED_ENVELOPE, $nowIso);

            return ['accepted' => false, 'peer_id' => 'unknown', 'fact' => null, 'reason' => self::REASON_MALFORMED_ENVELOPE];
        }
        $reason = $this->detectViolation($envelope, $nowIso);
        if ($reason !== null) {
            $hash = (string) ($envelope['content_hash'] ?? '');
            $this->quarantineRow($peerId, $hash, $reason, $nowIso);
            $this->recordViolation($peerId, $nowIso);

            return ['accepted' => false, 'peer_id' => $peerId, 'fact' => null, 'reason' => $reason];
        }

        return [
            'accepted' => true,
            'peer_id' => $peerId,
            'fact' => [
                'peer_id' => $peerId,
                'fact_id' => (string) $envelope['fact_id'],
                'content_hash' => (string) $envelope['content_hash'],
                'observed_at' => (string) $envelope['observed_at'],
            ],
            'reason' => null,
        ];
    }

    /**
     * @param  array<string,mixed>  $envelope
     */
    private function detectViolation(array $envelope, string $nowIso): ?string
    {
        foreach (['fact_id', 'content_hash', 'observed_at'] as $key) {
            if (! isset($envelope[$key]) || (string) $envelope[$key] === '') {
                return self::REASON_MALFORMED_ENVELOPE;
            }
        }
        if (isset($envelope['schema_version']) && (string) $envelope['schema_version'] !== 'atlas.loop.federation_envelope.v1') {
            return self::REASON_SCHEMA_VIOLATION;
        }
        $observedAt = (string) $envelope['observed_at'];
        $now = strtotime($nowIso);
        $observed = strtotime($observedAt);
        if ($now === false || $observed === false) {
            return self::REASON_MALFORMED_ENVELOPE;
        }
        if (abs($now - $observed) > $this->clockSkewBoundSeconds) {
            return self::REASON_CLOCK_SKEW;
        }
        if (isset($envelope['declared_content_hash']) && (string) $envelope['declared_content_hash'] !== (string) $envelope['content_hash']) {
            return self::REASON_CONTENT_HASH_MISMATCH;
        }
        if (! empty($envelope['is_replay'])) {
            return self::REASON_REPLAY;
        }

        return null;
    }

    private function quarantineRow(string $peerId, string $envelopeHash, string $reason, string $nowIso): void
    {
        $this->quarantine[$peerId] ??= [];
        $this->quarantine[$peerId][] = [
            'envelope_hash' => $envelopeHash,
            'reason' => $reason,
            'observed_at' => $nowIso,
        ];
    }

    private function recordViolation(string $peerId, string $nowIso): void
    {
        $ts = strtotime($nowIso);
        if ($ts === false) {
            return;
        }
        $this->violationWindow[$peerId] ??= [];
        $this->violationWindow[$peerId][] = $ts;
        // Drop entries outside the sliding window.
        $cutoff = $ts - $this->slidingWindowSeconds;
        $this->violationWindow[$peerId] = array_values(array_filter(
            $this->violationWindow[$peerId],
            static fn (int $t): bool => $t >= $cutoff,
        ));

        if (count($this->violationWindow[$peerId]) >= $this->violationThreshold) {
            $current = $this->peerTiers[$peerId] ?? self::TIER_TRUSTED;
            $this->peerTiers[$peerId] = match ($current) {
                self::TIER_TRUSTED => self::TIER_OBSERVED,
                self::TIER_OBSERVED => self::TIER_QUARANTINED,
                default => self::TIER_QUARANTINED,
            };
            // Reset the window so the same tier doesn't double-decay on the same K events.
            $this->violationWindow[$peerId] = [];
        }
    }

    /**
     * @return array<string, list<array<string,string>>>
     */
    public function quarantine(): array
    {
        return $this->quarantine;
    }

    /**
     * @return array<string, string>
     */
    public function peerTiers(): array
    {
        return $this->peerTiers;
    }
}
