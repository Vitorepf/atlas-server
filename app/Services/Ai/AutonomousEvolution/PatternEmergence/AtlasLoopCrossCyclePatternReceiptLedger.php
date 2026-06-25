<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\PatternEmergence;

use RuntimeException;

/**
 * Append-only NDJSON audit ledger for miner+stability run receipts.
 *
 * Each `record()` call appends exactly ONE line to the receipts file containing:
 *   N, K, miner_payload_sha256, stability_payload_sha256, recorded_at_utc, cycle_ids[].
 *
 * Fail-closed:
 *   - if the file is unwritable → throws RuntimeException, NEVER mutates state.
 *   - if miner/stability payloads disagree on the cycle-id set → throws
 *     {@see CrossCyclePatternIntegrityException}, NEVER writes.
 *
 * Append-only by construction: prior lines are never edited or removed.
 */
final class AtlasLoopCrossCyclePatternReceiptLedger
{
    public const SCHEMA = 'atlas.loop.cross_cycle_pattern_receipt.v1';

    public function __construct(private readonly string $ndjsonPath)
    {
        $dir = dirname($this->ndjsonPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
    }

    /**
     * @param  array<string,mixed>  $minerPayload
     * @param  array<string,mixed>  $stabilityPayload
     * @return array<string,mixed>  the written receipt
     */
    public function record(int $n, int $k, array $minerPayload, array $stabilityPayload, ?string $recordedAtUtc = null): array
    {
        $minerCycles = $this->extractCycleIds($minerPayload);
        $stabilityCycles = $this->extractCycleIds($stabilityPayload);
        if ($minerCycles !== $stabilityCycles) {
            throw new CrossCyclePatternIntegrityException(
                'miner and stability payloads disagree on cycle_ids: miner='.json_encode($minerCycles).' stability='.json_encode($stabilityCycles),
            );
        }

        $receipt = [
            'schema' => self::SCHEMA,
            'n' => $n,
            'k' => $k,
            'miner_payload_sha256' => hash('sha256', (string) json_encode($minerPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'stability_payload_sha256' => hash('sha256', (string) json_encode($stabilityPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'recorded_at_utc' => $recordedAtUtc ?? gmdate('Y-m-d\TH:i:s\Z'),
            'cycle_ids' => $minerCycles,
        ];

        $line = json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $handle = @fopen($this->ndjsonPath, 'a');
        if ($handle === false) {
            throw new RuntimeException('AtlasLoopCrossCyclePatternReceiptLedger: cannot open '.$this->ndjsonPath.' for append');
        }
        try {
            if (fwrite($handle, $line."\n") === false) {
                throw new RuntimeException('AtlasLoopCrossCyclePatternReceiptLedger: write failed');
            }
            fflush($handle);
        } finally {
            fclose($handle);
        }

        return $receipt;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function readAll(): array
    {
        if (! is_file($this->ndjsonPath)) {
            return [];
        }
        $rows = [];
        $handle = @fopen($this->ndjsonPath, 'r');
        if ($handle === false) {
            return [];
        }
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function extractCycleIds(array $payload): array
    {
        $raw = (array) ($payload['cycle_ids'] ?? []);
        $ids = array_values(array_map('strval', $raw));
        // Canonical order so two equivalent-but-differently-sorted inputs compare equal.
        sort($ids, SORT_STRING);

        return $ids;
    }
}

final class CrossCyclePatternIntegrityException extends RuntimeException
{
}
