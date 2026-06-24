<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\UnifiedReceipts;

use RuntimeException;

/**
 * OFFLINE-AUDIT JSONL EXPORTER for the unified receipt chain. Emits a self-contained file whose first line is
 * an {@see AtlasLoopUnifiedReceiptExportManifest} header and whose subsequent lines each carry one node copied
 * VERBATIM from the chain (stored hashes are NEVER recomputed — an exporter bug must not be able to mask a
 * tamper). Every line is canonical-JSON (ksort-recursive on assoc arrays), terminated by a single LF; the file
 * ends with exactly one trailing LF.
 *
 * MODES:
 *   - {@see full()}                    : the entire chain
 *   - {@see range(int $from, int $to)} : nodes whose seq falls within [from, to] (inclusive)
 *   - {@see sinceHash(string $head)}   : all nodes whose prev_hash chain advances past the given head_hash
 *
 * SAFETY:
 *   - The exporter NEVER overwrites silently — writing to an existing path throws unless force=true.
 *   - Deterministic-by-construction: same inputs ⇒ byte-identical file (modulo the exported_at_us field,
 *     which the caller controls via the injectable clock).
 */
final class AtlasLoopUnifiedReceiptExporter
{
    /** @var null|callable():int */
    private $clock;

    /**
     * @param  null|callable():int  $clock  microsecond UTC clock; defaults to (int) floor(microtime(true)*1e6)
     */
    public function __construct(
        private readonly AtlasLoopUnifiedReceiptChain $chain,
        ?callable $clock = null,
    ) {
        $this->clock = $clock;
    }

    public function full(string $outputPath, bool $force = false): AtlasLoopUnifiedReceiptExportManifest
    {
        $nodes = $this->collectNodes(null, null, null);

        return $this->writeFile($outputPath, $force, $nodes, ['mode' => 'full', 'from' => null, 'to' => null, 'since_hash' => null]);
    }

    public function range(string $outputPath, int $from, int $to, bool $force = false): AtlasLoopUnifiedReceiptExportManifest
    {
        $nodes = $this->collectNodes($from, $to, null);

        return $this->writeFile($outputPath, $force, $nodes, ['mode' => 'range', 'from' => $from, 'to' => $to, 'since_hash' => null]);
    }

    public function sinceHash(string $outputPath, string $headHash, bool $force = false): AtlasLoopUnifiedReceiptExportManifest
    {
        $nodes = $this->collectNodes(null, null, $headHash);

        return $this->writeFile($outputPath, $force, $nodes, ['mode' => 'since_hash', 'from' => null, 'to' => null, 'since_hash' => $headHash]);
    }

    /**
     * @return list<AtlasLoopUnifiedReceiptChainNode>
     */
    private function collectNodes(?int $from, ?int $to, ?string $sinceHash): array
    {
        $all = [];
        foreach ($this->chain->all() as $node) {
            $all[] = $node;
        }

        if ($sinceHash !== null && $sinceHash !== '') {
            $kept = [];
            $found = false;
            foreach ($all as $node) {
                if ($found) {
                    $kept[] = $node;

                    continue;
                }
                if ($node->node_hash === $sinceHash) {
                    $found = true;
                }
            }

            return $kept;
        }

        if ($from !== null || $to !== null) {
            $lo = $from ?? PHP_INT_MIN;
            $hi = $to ?? PHP_INT_MAX;

            return array_values(array_filter($all, static fn (AtlasLoopUnifiedReceiptChainNode $n): bool => $n->seq >= $lo && $n->seq <= $hi));
        }

        return $all;
    }

    /**
     * @param  list<AtlasLoopUnifiedReceiptChainNode>  $nodes
     * @param  array{mode:string, from:?int, to:?int, since_hash:?string}  $range
     */
    private function writeFile(string $outputPath, bool $force, array $nodes, array $range): AtlasLoopUnifiedReceiptExportManifest
    {
        if (is_file($outputPath) && ! $force) {
            throw new RuntimeException('Refusing to overwrite existing export file: '.$outputPath.' (pass force=true to overwrite)');
        }
        $dir = dirname($outputPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $manifest = new AtlasLoopUnifiedReceiptExportManifest(
            headHash: $this->chain->headHash(),
            totalNodes: count($nodes),
            exportedAtUs: $this->now(),
            range: $range,
        );

        $lines = [self::canonicalJson($manifest->toArray())];
        foreach ($nodes as $node) {
            $lines[] = self::canonicalJson($this->nodeAsArrayVerbatim($node));
        }

        // Single trailing LF, LF-only line endings, no trailing comma — by construction.
        file_put_contents($outputPath, implode("\n", $lines)."\n", LOCK_EX);

        return $manifest;
    }

    /**
     * Copy a node into an associative array WITHOUT recomputing any hash. The stored payload_hash, node_hash
     * and prev_hash are carried verbatim from the chain — an exporter bug cannot hide a tamper here.
     *
     * @return array<string,mixed>
     */
    private function nodeAsArrayVerbatim(AtlasLoopUnifiedReceiptChainNode $node): array
    {
        return [
            'node_hash' => $node->node_hash,
            'node_id' => $node->node_id,
            'occurred_at_us' => $node->recorded_at,
            'payload_hash' => $node->payload_hash,
            'prev_hash' => $node->prev_hash,
            'recorded_at_us' => $node->recorded_at,
            'seq' => $node->seq,
            'source_facts' => $node->source_facts_json,
            'source_ledger' => $node->source_ledger,
            'source_receipt_id' => $node->source_receipt_id,
        ];
    }

    private function now(): int
    {
        $clock = $this->clock;
        if (is_callable($clock)) {
            return (int) $clock();
        }

        return (int) floor(microtime(true) * 1_000_000);
    }

    /**
     * Canonical JSON: recursive ksort on assoc arrays, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE. Lists
     * preserve order. Deterministic across invocations for the same input.
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
