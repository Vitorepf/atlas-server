<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\UnifiedReceipts;

/**
 * TAMPER-EVIDENT VERIFIER for {@see AtlasLoopUnifiedReceiptChain}. Walks the chain JSONL head-to-tail and, for
 * each node, recomputes payload_hash from (source_facts_json + source_ledger + source_receipt_id) using the
 * SAME canonical-JSON scheme the appender used, and recomputes node_hash from (prev_hash || payload_hash). On
 * the first mismatch the walk halts and the seq/node_id/reason are reported.
 *
 * It also enforces three structural invariants the appender guarantees:
 *  - The first node's prev_hash is 64 zero hex chars (genesis).
 *  - Each non-genesis node's prev_hash equals the prior node's node_hash.
 *  - Sequence numbers are strictly monotonic (+1 per line, no gaps).
 *
 * READ-ONLY, PROVIDER-FREE: no LLM call, no Http facade, no network — pure on-disk byte verification. An empty
 * or missing JSONL file is a valid chain (ok=true, total_nodes=0).
 */
final class AtlasLoopUnifiedReceiptVerifier
{
    private const GENESIS_PREV_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /** the canonical JSON wrapper keys (alphabetic order is what canonicalize() emits) */
    private const REQUIRED_FIELDS = ['node_hash', 'node_id', 'payload_hash', 'prev_hash', 'seq', 'source_facts_json', 'source_ledger', 'source_receipt_id'];

    public function __construct(private readonly string $chainFile)
    {
    }

    public function verify(): AtlasLoopUnifiedReceiptVerificationReport
    {
        return $this->walk(null, null);
    }

    public function verifyRange(int $from, int $to): AtlasLoopUnifiedReceiptVerificationReport
    {
        return $this->walk($from, $to);
    }

    private function walk(?int $from, ?int $to): AtlasLoopUnifiedReceiptVerificationReport
    {
        if (! is_file($this->chainFile)) {
            return AtlasLoopUnifiedReceiptVerificationReport::clean(0);
        }

        $contents = (string) file_get_contents($this->chainFile);
        if (trim($contents) === '') {
            return AtlasLoopUnifiedReceiptVerificationReport::clean(0);
        }

        $lines = preg_split("/\r?\n/", trim($contents)) ?: [];
        $previousNodeHash = self::GENESIS_PREV_HASH;
        $previousSeq = 0;
        $totalNodes = 0;
        $isFirst = true;

        foreach ($lines as $lineNumber => $line) {
            if ($line === '') {
                continue;
            }
            $totalNodes++;

            $decoded = json_decode($line, true);
            if (! is_array($decoded)) {
                return AtlasLoopUnifiedReceiptVerificationReport::broken(
                    $totalNodes,
                    $previousSeq + 1,
                    AtlasLoopUnifiedReceiptVerificationReport::CANONICAL_JSON_DRIFT,
                    null,
                );
            }

            foreach (self::REQUIRED_FIELDS as $field) {
                if (! array_key_exists($field, $decoded) || $decoded[$field] === '' || $decoded[$field] === null) {
                    return AtlasLoopUnifiedReceiptVerificationReport::broken(
                        $totalNodes,
                        (int) ($decoded['seq'] ?? ($previousSeq + 1)),
                        AtlasLoopUnifiedReceiptVerificationReport::MISSING_FIELD,
                        (string) ($decoded['node_id'] ?? '') ?: null,
                    );
                }
            }

            $seq = (int) $decoded['seq'];
            $nodeId = (string) $decoded['node_id'];
            $prevHash = (string) $decoded['prev_hash'];
            $storedPayloadHash = (string) $decoded['payload_hash'];
            $storedNodeHash = (string) $decoded['node_hash'];

            $inRange = ($from === null || $seq >= $from) && ($to === null || $seq <= $to);

            if ($isFirst) {
                if ($prevHash !== self::GENESIS_PREV_HASH) {
                    if ($inRange) {
                        return AtlasLoopUnifiedReceiptVerificationReport::broken(
                            $totalNodes, $seq,
                            AtlasLoopUnifiedReceiptVerificationReport::GENESIS_PREV_NOT_ZERO,
                            $nodeId,
                        );
                    }
                }
                $isFirst = false;
            } else {
                if ($seq !== $previousSeq + 1) {
                    if ($inRange) {
                        return AtlasLoopUnifiedReceiptVerificationReport::broken(
                            $totalNodes, $seq,
                            AtlasLoopUnifiedReceiptVerificationReport::SEQ_NON_MONOTONIC,
                            $nodeId,
                        );
                    }
                }
                if (! hash_equals($previousNodeHash, $prevHash)) {
                    if ($inRange) {
                        return AtlasLoopUnifiedReceiptVerificationReport::broken(
                            $totalNodes, $seq,
                            AtlasLoopUnifiedReceiptVerificationReport::PREV_LINK_BROKEN,
                            $nodeId,
                        );
                    }
                }
            }

            $sourceFactsJson = (string) $decoded['source_facts_json'];
            $sourceLedger = (string) $decoded['source_ledger'];
            $sourceReceiptId = (string) $decoded['source_receipt_id'];

            $facts = json_decode($sourceFactsJson, true);
            if (! is_array($facts)) {
                if ($inRange) {
                    return AtlasLoopUnifiedReceiptVerificationReport::broken(
                        $totalNodes, $seq,
                        AtlasLoopUnifiedReceiptVerificationReport::CANONICAL_JSON_DRIFT,
                        $nodeId,
                    );
                }
            } else {
                $recomputedPayloadHash = hash('sha256', self::canonicalJson([
                    'source_facts_json' => $facts,
                    'source_ledger' => $sourceLedger,
                    'source_receipt_id' => $sourceReceiptId,
                ]));

                if (! hash_equals($storedPayloadHash, $recomputedPayloadHash)) {
                    if ($inRange) {
                        return AtlasLoopUnifiedReceiptVerificationReport::broken(
                            $totalNodes, $seq,
                            AtlasLoopUnifiedReceiptVerificationReport::PAYLOAD_HASH_MISMATCH,
                            $nodeId,
                        );
                    }
                }

                $recomputedNodeHash = hash('sha256', $prevHash.$storedPayloadHash);
                if (! hash_equals($storedNodeHash, $recomputedNodeHash)) {
                    if ($inRange) {
                        return AtlasLoopUnifiedReceiptVerificationReport::broken(
                            $totalNodes, $seq,
                            AtlasLoopUnifiedReceiptVerificationReport::NODE_HASH_MISMATCH,
                            $nodeId,
                        );
                    }
                }
            }

            $previousNodeHash = $storedNodeHash;
            $previousSeq = $seq;
        }

        return AtlasLoopUnifiedReceiptVerificationReport::clean($totalNodes);
    }

    /**
     * The SAME canonical-JSON scheme the appender uses: nested ksort on assoc arrays, lists preserved,
     * JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE. Replays the appender's payload bytes exactly so
     * payload_hash recomputes deterministically.
     */
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
            $out = [];
            foreach ($value as $item) {
                $out[] = self::canonicalize($item);
            }

            return $out;
        }
        ksort($value);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::canonicalize($v);
        }

        return $out;
    }
}
