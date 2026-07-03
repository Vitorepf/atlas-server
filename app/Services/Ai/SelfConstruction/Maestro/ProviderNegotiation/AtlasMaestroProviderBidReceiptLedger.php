<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;

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
    /** Only these keys survive in a redacted criteria_trace step — anything else is provider-sensitive raw metadata. */
    private const ALLOWED_TRACE_KEYS = ['provider_id', 'eliminated_by', 'cmp'];

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
        $entry = null;
        // Dedup + prev-hash chain derivation run INSIDE the day file's exclusive write lock:
        // same-day concurrent appends can neither duplicate a task entry nor fork the chain.
        // A day rollover (empty new file) still chains from the previous day via lastEntryHash().
        (new JsonlReceiptStore($this->pathForDate($recordedAtIso)))->appendWith(function (?string $lastLine) use (
            $taskId, $envelopeHash, $bidHashes, $winnerProviderId, $decisiveCriterion, $criteriaTrace, $recordedAtIso, &$entry,
        ): array {
            if ($this->recall($taskId) !== null) {
                throw new LedgerImmutableViolation('ledger_already_has_entry_for_task:'.$taskId);
            }
            $lastDecoded = $lastLine !== null ? json_decode($lastLine, true) : null;
            $prev = is_array($lastDecoded)
                ? (string) ($lastDecoded['entry_sha256'] ?? str_repeat('0', 64))
                : $this->lastEntryHash();
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

            return $entry->toArray();
        });

        /** @var BidReceiptEntry $entry */
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
     * Records the eventual outcome (e.g. 'success', 'give_back') for a previously-appended task.
     */
    public function attachOutcome(string $taskId, string $outcome, string $recordedAtIso): void
    {
        (new JsonlReceiptStore($this->root().'/outcomes.jsonl'))
            ->append(['task_id' => $taskId, 'outcome' => $outcome, 'recorded_at_iso' => $recordedAtIso]);
    }

    public function recallOutcome(string $taskId): ?string
    {
        $path = $this->root().'/outcomes.jsonl';
        if (! is_file($path)) {
            return null;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true);
            if ($row === null && json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('AtlasMaestroProviderBidReceiptLedger: corrupt outcome ledger line: '.substr($line, 0, 128), 1);
            }
            if (is_array($row) && ($row['task_id'] ?? '') === $taskId) {
                return (string) $row['outcome'];
            }
        }

        return null;
    }

    /**
     * @return array{win_count:int, loss_count:int, total_decisions:int, success_count:int, weak_green_count:int, give_back_count:int}
     */
    public function aggregateForWorker(string $providerId): array
    {
        $wins = 0;
        $losses = 0;
        $successCount = 0;
        $weakGreenCount = 0;
        $giveBackCount = 0;
        foreach ($this->allEntries() as $entry) {
            if ($entry->winnerProviderId === $providerId) {
                $wins++;
                match ($this->recallOutcome($entry->taskId)) {
                    'success' => $successCount++,
                    'weak_green' => $weakGreenCount++,
                    'give_back' => $giveBackCount++,
                    default => null,
                };
            }
            foreach ($entry->criteriaTrace as $step) {
                if (($step['provider_id'] ?? '') === $providerId) {
                    $losses++;
                }
            }
        }

        return [
            'win_count' => $wins,
            'loss_count' => $losses,
            'total_decisions' => $wins + $losses,
            'success_count' => $successCount,
            'weak_green_count' => $weakGreenCount,
            'give_back_count' => $giveBackCount,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function export(int $limit = 20): array
    {
        $all = $this->allEntries();

        return array_map(
            fn (BidReceiptEntry $e): array => $e->toArray(),
            array_values(array_slice($all, max(0, count($all) - $limit))),
        );
    }

    /**
     * Same as {@see export()} but strips any criteria_trace step keys outside the allowlist —
     * provider-sensitive raw metadata a caller may have stuffed into criteriaTrace never leaves
     * this method.
     *
     * @return list<array<string,mixed>>
     */
    public function exportRedacted(int $limit = 20): array
    {
        return array_map(
            function (array $row): array {
                $row['criteria_trace'] = array_map(
                    fn (array $step): array => array_intersect_key($step, array_flip(self::ALLOWED_TRACE_KEYS)),
                    (array) ($row['criteria_trace'] ?? []),
                );

                return $row;
            },
            $this->export($limit),
        );
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
