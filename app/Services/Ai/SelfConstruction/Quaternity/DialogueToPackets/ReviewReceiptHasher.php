<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets;

/**
 * Deterministic hash helpers for the review gate — all three hashes the bi-directional receipt depends on:
 *  - proposal_hash       : sha256 of the canonical proposal JSON (so tamper on the proposed file is detectable)
 *  - approval_hash       : sha256 of (proposal_hash || operator_signature || decision_receipt || decided_at_utc)
 *                          so approval is bound to BOTH the proposal AND the operator's decision context
 *  - cortex_snapshot_hash: sha256 of the canonical anchor citations (so the grounding the operator saw is fixed)
 */
final class ReviewReceiptHasher
{
    /**
     * @param  array<string,mixed>  $proposal  the parsed ProposedPacketShape array
     */
    public static function proposalHash(array $proposal): string
    {
        return hash('sha256', self::canonicalJson($proposal));
    }

    /**
     * @param  list<string>  $citations
     */
    public static function cortexSnapshotHash(array $citations): string
    {
        $sorted = $citations;
        sort($sorted, SORT_STRING);

        return hash('sha256', self::canonicalJson($sorted));
    }

    public static function approvalHash(string $proposalHash, string $operatorSignature, string $decisionReceipt, string $decidedAtUtc): string
    {
        return hash('sha256', $proposalHash.'|'.$operatorSignature.'|'.$decisionReceipt.'|'.$decidedAtUtc);
    }

    private static function canonicalJson(mixed $value): string
    {
        return (string) json_encode(self::canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'canonicalize'], $value);
        }
        ksort($value);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::canonicalize($v);
        }

        return $out;
    }
}
