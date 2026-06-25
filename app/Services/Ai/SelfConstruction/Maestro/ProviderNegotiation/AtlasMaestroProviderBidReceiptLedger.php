<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation;

/**
 * Append-only persistence of every (TaskEnvelope, BidSet, BidArbitrationVerdict) triple.
 *
 * Backed by storage/atlas/maestro/provider-bid-ledger/YYYY-MM-DD.jsonl. Each entry stores a
 * canonical snapshot, NEVER a wide schema. Entries are hash-chained via prev_entry_sha256 so
 * tampering is detectable.
 *
 * Refuses to mutate or delete past entries — throws {@see LedgerImmutableViolation} on attempts.
 */
final class AtlasMaestroProviderBidReceiptLedger
{
    private static ?string $rootOverride = null;

    public static function setRootForTesting(?string $root): void
    {
        self::$rootOverride = $root;
    }

    public function __construct(
        private readonly BidReceiptHashChain $hashChain = new BidReceiptHashChain(),
    ) {}

    /**
     * @param  list<string>  $bidHashes
     * @param  list<array<string,mixed>>  $criteriaTrace
     */
    public function append(
        string $taskId,
        string $envelopeHash,
        array $bidHashes,
        string $winnerProviderId,
        string $decisiveCriterion,
        array $criteriaTrace,
        string $recordedAtIso,
    ): BidReceiptEntry {
        $existing = $this->recall($taskId);
        if ($existing !== null) {
            throw new LedgerImmutableViolation('ledger_already_has_entry_for_task:'.$taskId);
        }
        $prev = $this->lastEntryHash();
        $body = [
            'bid_hashes' => array_values($bidHashes),
            'criteria_trace' => array_values($criteriaTrace),
            'decisive_criterion' => $decisiveCriterion,
            'envelope_hash' => $envelopeHash,
            'recorded_at_iso' => $recordedAtIso,
            'task_id' => $taskId,
            'winner_provider_id' => $winnerProviderId,
        ];
        $bodySha = $this->hashChain->bodyHash($body);
        $entrySha = $this->hashChain->chainLink($prev, $bodySha);

        $entry = new BidReceiptEntry(
            taskId: $taskId,
            envelopeHash: $envelopeHash,
            bidHashes: array_values($bidHashes),
            winnerProviderId: $winnerProviderId,
            decisiveCriterion: $decisiveCriterion,
            criteriaTrace: array_values($criteriaTrace),
            recordedAtIso: $recordedAtIso,
            prevEntrySha256: $prev,
            entrySha256: $entrySha,
        );
        $this->appendLine($recordedAtIso, $entry);

        return $entry;
    }

    public function recall(string $taskId): ?BidReceiptEntry
    {
        foreach ($this->allEntries() as $entry) {
            if ($entry->taskId === $taskId) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return list<BidReceiptEntry>
     */
    public function history(string $providerId, ?string $sinceIso = null): array
    {
        $out = [];
        foreach ($this->allEntries() as $entry) {
            if ($entry->winnerProviderId !== $providerId) {
                continue;
            }
            if ($sinceIso !== null && $entry->recordedAtIso < $sinceIso) {
                continue;
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @return list<BidReceiptEntry>
     */
    public function allEntries(): array
    {
        $rows = [];
        foreach ($this->ledgerFiles() as $path) {
            $fh = @fopen($path, 'rb');
            if ($fh === false) {
                continue;
            }
            try {
                while (($line = fgets($fh)) !== false) {
                    $line = rtrim($line, "\n");
                    if ($line === '') {
                        continue;
                    }
                    $decoded = json_decode($line, true);
                    if (! is_array($decoded)) {
                        continue;
                    }
                    $rows[] = $this->fromArray($decoded);
                }
            } finally {
                fclose($fh);
            }
        }

        return $rows;
    }

    public function root(): string
    {
        return self::$rootOverride ?? storage_path('atlas/maestro/provider-bid-ledger');
    }

    private function pathForDate(string $iso): string
    {
        $date = substr($iso, 0, 10);

        return $this->root().'/'.$date.'.jsonl';
    }

    /**
     * @return list<string>
     */
    private function ledgerFiles(): array
    {
        $root = $this->root();
        if (! is_dir($root)) {
            return [];
        }
        $files = (array) glob($root.'/*.jsonl');
        $out = [];
        foreach ($files as $f) {
            if (is_string($f)) {
                $out[] = $f;
            }
        }
        sort($out, SORT_STRING);

        return $out;
    }

    private function appendLine(string $recordedAtIso, BidReceiptEntry $entry): void
    {
        $path = $this->pathForDate($recordedAtIso);
        $dir = \dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new \RuntimeException('bid_receipt_ledger_mkdir_failed:'.$dir);
        }
        $line = (string) json_encode($entry->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        @file_put_contents($path, $line."\n", FILE_APPEND | LOCK_EX);
    }

    private function lastEntryHash(): string
    {
        $files = $this->ledgerFiles();
        if ($files === []) {
            return str_repeat('0', 64);
        }
        $lastFile = $files[count($files) - 1];
        $lines = file($lastFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        if ($lines === []) {
            return str_repeat('0', 64);
        }
        $decoded = json_decode(end($lines), true);

        return is_array($decoded) ? (string) ($decoded['entry_sha256'] ?? str_repeat('0', 64)) : str_repeat('0', 64);
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function fromArray(array $row): BidReceiptEntry
    {
        return new BidReceiptEntry(
            taskId: (string) ($row['task_id'] ?? ''),
            envelopeHash: (string) ($row['envelope_hash'] ?? ''),
            bidHashes: array_values((array) ($row['bid_hashes'] ?? [])),
            winnerProviderId: (string) ($row['winner_provider_id'] ?? ''),
            decisiveCriterion: (string) ($row['decisive_criterion'] ?? ''),
            criteriaTrace: array_values((array) ($row['criteria_trace'] ?? [])),
            recordedAtIso: (string) ($row['recorded_at_iso'] ?? ''),
            prevEntrySha256: (string) ($row['prev_entry_sha256'] ?? ''),
            entrySha256: (string) ($row['entry_sha256'] ?? ''),
        );
    }
}
