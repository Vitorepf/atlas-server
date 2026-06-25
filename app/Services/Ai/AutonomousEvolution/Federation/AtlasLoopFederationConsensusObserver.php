<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Federation;

/**
 * Pure FACT producer. Emits a derived FACT `consensus.agreed` when ≥N INDEPENDENT peers have
 * published the SAME underlying fact_id with matching content_hash within a bounded staleness
 * window.
 *
 * NEVER a vote, NEVER a score, NEVER a quorum-decision. This is an observation: "these N peers
 * agree on this fact". Consumer organs may choose to weight it; the observer itself never blocks,
 * grades or promotes. Output is appended to the local FACTS stream.
 *
 * Peer reports input shape:
 *   { peer_id:string, fact_id:string, content_hash:string, observed_at:iso8601 }
 */
final class AtlasLoopFederationConsensusObserver
{
    public const SCHEMA = 'atlas.loop.federation_consensus.v1';

    public const FACT_KIND = 'consensus.agreed';

    public const DEFAULT_PEER_THRESHOLD = 2;

    public const DEFAULT_STALENESS_SECONDS = 600;

    public function __construct(
        private readonly int $peerThreshold = self::DEFAULT_PEER_THRESHOLD,
        private readonly int $stalenessSeconds = self::DEFAULT_STALENESS_SECONDS,
    ) {}

    /**
     * @param  list<array<string,mixed>>  $peerReports
     * @return list<array<string,mixed>> derived FACTs (empty when no consensus reached)
     */
    public function observe(array $peerReports, string $nowIso): array
    {
        $now = strtotime($nowIso);
        if ($now === false) {
            return [];
        }
        $threshold = max(2, $this->peerThreshold);

        // Group by (fact_id, content_hash) → unique set of peer_ids whose observation is within window.
        $groups = [];
        foreach ($peerReports as $report) {
            if (! is_array($report)) {
                continue;
            }
            $peerId = (string) ($report['peer_id'] ?? '');
            $factId = (string) ($report['fact_id'] ?? '');
            $hash = (string) ($report['content_hash'] ?? '');
            $observedAt = (string) ($report['observed_at'] ?? '');
            if ($peerId === '' || $factId === '' || $hash === '' || $observedAt === '') {
                continue;
            }
            $ts = strtotime($observedAt);
            if ($ts === false) {
                continue;
            }
            if (($now - $ts) > $this->stalenessSeconds) {
                continue;
            }
            $key = $factId."\0".$hash;
            $groups[$key]['fact_id'] ??= $factId;
            $groups[$key]['content_hash'] ??= $hash;
            $groups[$key]['peers'][$peerId] = $observedAt;
        }

        $derived = [];
        ksort($groups, SORT_STRING);
        foreach ($groups as $g) {
            $peers = $g['peers'] ?? [];
            if (count($peers) < $threshold) {
                continue;
            }
            ksort($peers, SORT_STRING);
            $derived[] = [
                'schema_version' => self::SCHEMA,
                'kind' => self::FACT_KIND,
                'fact_id' => (string) $g['fact_id'],
                'content_hash' => (string) $g['content_hash'],
                'participating_peer_ids' => array_keys($peers),
                'peer_observations' => $peers,
                'observed_at' => $nowIso,
            ];
        }

        return $derived;
    }
}
