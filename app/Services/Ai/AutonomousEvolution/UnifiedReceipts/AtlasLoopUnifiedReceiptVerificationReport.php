<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\UnifiedReceipts;

/**
 * The result of {@see AtlasLoopUnifiedReceiptVerifier::verify()}: ok=true on a fully-intact chain (or an empty
 * chain), otherwise ok=false with the FIRST broken seq, its node_id, and the canonical break reason. The
 * verifier stops at the first break so the operator can audit it without noise from cascading mismatches.
 */
final class AtlasLoopUnifiedReceiptVerificationReport
{
    /** payload_hash recomputed from source_facts_json+source_ledger+source_receipt_id != stored payload_hash */
    public const PAYLOAD_HASH_MISMATCH = 'PAYLOAD_HASH_MISMATCH';

    /** node_hash recomputed from (prev_hash || payload_hash) != stored node_hash */
    public const NODE_HASH_MISMATCH = 'NODE_HASH_MISMATCH';

    /** node.prev_hash does not equal the prior node's node_hash (or genesis is wrong) */
    public const PREV_LINK_BROKEN = 'PREV_LINK_BROKEN';

    /** sequence numbers do not advance by exactly +1 line-by-line */
    public const SEQ_NON_MONOTONIC = 'SEQ_NON_MONOTONIC';

    /** the first node's prev_hash is not 64 zero hex chars */
    public const GENESIS_PREV_NOT_ZERO = 'GENESIS_PREV_NOT_ZERO';

    /** source_facts_json is not a valid JSON object — canonicalization cannot be replayed */
    public const CANONICAL_JSON_DRIFT = 'CANONICAL_JSON_DRIFT';

    /** a required field (seq, prev_hash, payload_hash, node_hash, source_ledger, ...) is missing/empty */
    public const MISSING_FIELD = 'MISSING_FIELD';

    public function __construct(
        public readonly bool $ok,
        public readonly int $totalNodes,
        public readonly ?int $firstBreakSeq = null,
        public readonly ?string $breakReason = null,
        public readonly ?string $brokenNodeId = null,
    ) {
    }

    public static function clean(int $totalNodes): self
    {
        return new self(true, $totalNodes);
    }

    public static function broken(int $totalNodes, int $seq, string $reason, ?string $nodeId): self
    {
        return new self(false, $totalNodes, $seq, $reason, $nodeId);
    }
}
