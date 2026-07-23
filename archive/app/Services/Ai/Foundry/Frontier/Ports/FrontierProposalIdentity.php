<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\Frontier\Ports;

use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * Canonical proposal identity — the ONE formula reused everywhere
 * (proposal_hash provenance, the I6 dedup key, and the inbox candidate_hash
 * basis 'sha256:'+proposal_hash). This is the SINGLE source of truth; no other
 * identity formula exists anywhere in AP-C.
 *
 * sha256 over a stable, sorted-key projection of:
 *   - title
 *   - thesis
 *   - cited_anchor_ids: sorted(unique(evidence_refs[].anchor_id))
 *   - packet_fingerprint: sorted(proposed_packets[].{kind, owner_candidate, label})
 */
final class FrontierProposalIdentity
{
    /**
     * @param  array<string,mixed>  $proposal
     */
    public static function of(array $proposal): string
    {
        $anchorIds = [];
        foreach ((array) ($proposal['evidence_refs'] ?? []) as $ref) {
            $anchorId = is_array($ref) ? ($ref['anchor_id'] ?? null) : null;
            if (is_string($anchorId) && $anchorId !== '') {
                $anchorIds[] = $anchorId;
            }
        }
        $anchorIds = array_values(array_unique($anchorIds));
        sort($anchorIds);

        $packetFingerprint = [];
        foreach ((array) ($proposal['proposed_packets'] ?? []) as $packet) {
            if (! is_array($packet)) {
                continue;
            }
            $packetFingerprint[] = [
                'kind' => (string) ($packet['kind'] ?? ''),
                'label' => (string) ($packet['label'] ?? ''),
                'owner_candidate' => (string) ($packet['owner_candidate'] ?? ''),
            ];
        }
        usort(
            $packetFingerprint,
            static fn (array $a, array $b): int => MissionCanonicalHash::canonicalJson($a) <=> MissionCanonicalHash::canonicalJson($b),
        );

        return MissionCanonicalHash::sha256([
            'cited_anchor_ids' => $anchorIds,
            'packet_fingerprint' => $packetFingerprint,
            'thesis' => (string) ($proposal['thesis'] ?? ''),
            'title' => (string) ($proposal['title'] ?? ''),
        ]);
    }
}
