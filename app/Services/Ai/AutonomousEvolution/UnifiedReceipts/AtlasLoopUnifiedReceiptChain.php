<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\UnifiedReceipts;


use App\Services\Ai\SelfConstruction\Support\RecursivelyCanonicalizesArrays;
use RuntimeException;

final class AtlasLoopUnifiedReceiptChain
{
    use RecursivelyCanonicalizesArrays;

    private const GENESIS_PREV_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(
        private readonly ?string $path = null,
        private readonly mixed $clock = null,
    ) {}

    /**
     * @param  array{
     *   facts:array<string,mixed>,
     *   receipt_id:string,
     *   source_ledger:string
     * }  $receipt
     */
    public function append(array $receipt): AtlasLoopUnifiedReceiptChainNode
    {
        $path = $this->path();
        $directory = dirname($path);
        if (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create unified receipt chain directory.');
        }

        $handle = fopen($path, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('Unable to open unified receipt chain file.');
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to acquire unified receipt chain lock.');
            }

            $contents = stream_get_contents($handle);
            if ($contents === false) {
                throw new RuntimeException('Unable to read unified receipt chain.');
            }

            $nodes = $this->decodeLines($contents);
            $previous = $nodes === [] ? null : $nodes[array_key_last($nodes)];
            $prevHash = $previous?->node_hash ?? self::GENESIS_PREV_HASH;
            $seq = ($previous?->seq ?? 0) + 1;

            $node = $this->makeNode($receipt, $prevHash, $seq);
            $line = $this->encodeNode($node)."\n";

            fseek($handle, 0, SEEK_END);
            if (fwrite($handle, $line) === false) {
                throw new RuntimeException('Unable to append unified receipt chain node.');
            }
            fflush($handle);

            return $node;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function latest(): ?AtlasLoopUnifiedReceiptChainNode
    {
        $nodes = $this->nodes();

        return $nodes === [] ? null : $nodes[array_key_last($nodes)];
    }

    /**
     * @return iterable<AtlasLoopUnifiedReceiptChainNode>
     */
    public function all(): iterable
    {
        foreach ($this->nodes() as $node) {
            yield $node;
        }
    }

    public function headHash(): string
    {
        return $this->latest()?->node_hash ?? self::GENESIS_PREV_HASH;
    }

    /**
     * @param  array{
     *   facts:array<string,mixed>,
     *   receipt_id:string,
     *   source_ledger:string
     * }  $receipt
     */
    private function makeNode(array $receipt, string $prevHash, int $seq): AtlasLoopUnifiedReceiptChainNode
    {
        $sourceLedger = trim((string) ($receipt['source_ledger'] ?? ''));
        $sourceReceiptId = trim((string) ($receipt['receipt_id'] ?? ''));
        $facts = is_array($receipt['facts'] ?? null) ? $receipt['facts'] : null;

        if ($sourceLedger === '' || $sourceReceiptId === '' || $facts === null) {
            throw new RuntimeException('Unified receipt append requires source_ledger, receipt_id, and facts.');
        }

        $sourceFactsJson = $this->encodeVerbatimJson($facts);
        $payloadHash = hash('sha256', $this->encodeJson($this->canonicalize([
            'source_facts_json' => $facts,
            'source_ledger' => $sourceLedger,
            'source_receipt_id' => $sourceReceiptId,
        ])));
        $nodeHash = hash('sha256', $prevHash.$payloadHash);
        $recordedAt = $this->now();
        $nodeId = $this->deterministicNodeId($seq, $nodeHash);

        if ($nodeHash !== hash('sha256', $prevHash.$payloadHash)) {
            throw new RuntimeException('Unified receipt chain hash mismatch during append.');
        }

        return new AtlasLoopUnifiedReceiptChainNode(
            node_id: $nodeId,
            source_ledger: $sourceLedger,
            source_receipt_id: $sourceReceiptId,
            source_facts_json: $sourceFactsJson,
            prev_hash: $prevHash,
            payload_hash: $payloadHash,
            node_hash: $nodeHash,
            recorded_at: $recordedAt,
            seq: $seq,
        );
    }

    /**
     * @return list<AtlasLoopUnifiedReceiptChainNode>
     */
    private function nodes(): array
    {
        $path = $this->path();
        if (! is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Unable to read unified receipt chain.');
        }

        return $this->decodeLines($contents);
    }

    private function path(): string
    {
        return $this->path ?? storage_path('atlas/loop/unified_receipts.jsonl');
    }

    /**
     * @return list<AtlasLoopUnifiedReceiptChainNode>
     */
    private function decodeLines(string $contents): array
    {
        $nodes = [];
        $lines = preg_split("/\r?\n/", trim($contents));
        if (! is_array($lines)) {
            return [];
        }

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                throw new RuntimeException('Malformed unified receipt chain node.');
            }

            $nodes[] = new AtlasLoopUnifiedReceiptChainNode(
                node_id: (string) ($decoded['node_id'] ?? ''),
                source_ledger: (string) ($decoded['source_ledger'] ?? ''),
                source_receipt_id: (string) ($decoded['source_receipt_id'] ?? ''),
                source_facts_json: (string) ($decoded['source_facts_json'] ?? ''),
                prev_hash: (string) ($decoded['prev_hash'] ?? ''),
                payload_hash: (string) ($decoded['payload_hash'] ?? ''),
                node_hash: (string) ($decoded['node_hash'] ?? ''),
                recorded_at: (int) ($decoded['recorded_at'] ?? 0),
                seq: (int) ($decoded['seq'] ?? 0),
            );
        }

        return $nodes;
    }

    private function encodeNode(AtlasLoopUnifiedReceiptChainNode $node): string
    {
        return $this->encodeJson($node->toArray());
    }

    /**
     * @param  array<string,mixed>|list<mixed>  $value
     * @return array<string,mixed>|list<mixed>
     */

    /**
     * @param  array<string,mixed>|list<mixed>  $payload
     */
    private function encodeJson(array $payload): string
    {
        $canonical = $this->canonicalize($payload);
        $encoded = json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($encoded)) {
            throw new RuntimeException('Unable to encode unified receipt chain JSON.');
        }

        return $encoded;
    }

    /**
     * @param  array<string,mixed>|list<mixed>  $payload
     */
    private function encodeVerbatimJson(array $payload): string
    {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($encoded)) {
            throw new RuntimeException('Unable to encode unified receipt facts JSON.');
        }

        return $encoded;
    }

    private function now(): int
    {
        if (is_callable($this->clock)) {
            return (int) ($this->clock)();
        }

        return (int) floor(microtime(true) * 1000000);
    }

    private function deterministicNodeId(int $seq, string $nodeHash): string
    {
        $hex = substr(hash('sha256', $seq.'|'.$nodeHash), 0, 32);
        $hex[12] = '7';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
