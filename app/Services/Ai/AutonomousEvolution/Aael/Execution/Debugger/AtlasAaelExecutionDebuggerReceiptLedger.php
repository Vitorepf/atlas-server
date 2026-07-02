<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Aael\Execution\Debugger;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use RuntimeException;
use Throwable;

final class AtlasAaelExecutionDebuggerReceiptLedger
{
    public const SCHEMA = 'atlas.aael.execution.debugger.receipt.v2';
    public const GENESIS_PREV_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(private readonly string $storageRoot)
    {
        if (! is_dir($this->storageRoot)) {
            @mkdir($this->storageRoot, 0o755, true);
        }
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed> the persisted canonical entry
     */
    public function append(string $runId, int $stepIndex, string $event, string $tsIso8601, array $extra = []): array
    {
        $entry = null;
        try {
            // Prev-hash derivation from the current tail runs INSIDE the store's exclusive lock.
            (new JsonlReceiptStore($this->ledgerPath($runId)))->appendWith(
                function (?string $lastLine) use ($runId, $stepIndex, $event, $tsIso8601, $extra, &$entry): array {
                    $prevHash = $lastLine === null ? self::GENESIS_PREV_HASH : hash('sha256', $lastLine);
                    $entry = new DebuggerReceiptEntry($tsIso8601, $runId, $stepIndex, $event, $prevHash, $extra);

                    // Round-trip through the entry's own canonical bytes so the stored line stays
                    // byte-identical to canonicalBytes() (replay() hash-chains over raw lines).
                    return (array) json_decode($entry->canonicalBytes(), true);
                },
            );
        } catch (RuntimeException|\InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        return $entry->toCanonicalArray();
    }

    /**
     * @return list<array<string,mixed>>
     * @throws HashChainBrokenException
     */
    public function replay(string $runId): array
    {
        $rows = [];
        $expected = self::GENESIS_PREV_HASH;
        $index = 0;
        foreach ((new JsonlReceiptStore($this->ledgerPath($runId)))->rawLines() as $line) {
            $decoded = json_decode((string) $line, true);
            if (! is_array($decoded)) {
                throw new HashChainBrokenException(sprintf('malformed_line run_id=%s index=%d', $runId, $index));
            }
            $prev = (string) ($decoded['prev_entry_sha256'] ?? '');
            if ($prev !== $expected) {
                throw new HashChainBrokenException(sprintf('hash_chain_broken run_id=%s index=%d expected=%s got=%s', $runId, $index, $expected, $prev));
            }
            $canonical = $this->reCanonicalize($decoded);
            if ($canonical !== (string) $line) {
                throw new HashChainBrokenException(sprintf('canonical_mismatch run_id=%s index=%d', $runId, $index));
            }
            $expected = hash('sha256', $canonical);
            $rows[] = $decoded;
            $index++;
        }

        return $rows;
    }

    /** @param array<string,mixed> $decoded */
    private function reCanonicalize(array $decoded): string
    {
        ksort($decoded);

        return (string) json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function ledgerPath(string $runId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $runId) ?? $runId;

        return rtrim($this->storageRoot, '/').'/'.$safe.'.receipts.jsonl';
    }
}

final class HashChainBrokenException extends RuntimeException {}
