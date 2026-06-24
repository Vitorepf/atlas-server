<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Trinity\ThreeWay;

use RuntimeException;

/**
 * The proven result of ONE Trinity cycle: the three primitive receipt ids plus the canonical TrinityFact stream
 * that the merger emitted for the cycle. Co-located with the receipt chain because only this allowed_file owns
 * the contract — it is the typed input to {@see AtlasLoopTrinityReceiptChain::append()}.
 */
final class TrinityCycleResult
{
    /**
     * @param  list<array<string,mixed>>  $factStream  canonical TrinityFact stream for the cycle (merger output)
     */
    public function __construct(
        public readonly string $cycleId,
        public readonly string $loopReceiptId,
        public readonly string $cortexReceiptId,
        public readonly string $maestroReceiptId,
        public readonly array $factStream,
    ) {
    }
}

/**
 * Thrown when {@see AtlasLoopTrinityReceiptChain::append()} is asked to record a cycleId that already has an
 * entry — the chain is append-only and each cycle appears exactly once. Co-located with the chain.
 */
final class TrinityReceiptChainDuplicateCycleException extends RuntimeException
{
}

/**
 * One tamper-evident link in the Trinity receipt chain. {@see $prevCycleHash} is the {@see entryHash()} of the
 * immediately prior entry (or {@see AtlasLoopTrinityReceiptChain::GENESIS_PREV_HASH} for the first), so any byte
 * mutated in an intermediate entry changes that entry's hash and breaks the next entry's link. Co-located.
 */
final class TrinityReceiptChainEntry
{
    public function __construct(
        public readonly string $cycleId,
        public readonly string $prevCycleHash,
        public readonly string $loopReceiptId,
        public readonly string $cortexReceiptId,
        public readonly string $maestroReceiptId,
        public readonly string $factStreamHash,
        public readonly string $auditResultHash,
    ) {
    }

    /**
     * Canonical, fixed-order representation — the exact bytes that get hashed and persisted. Key order is
     * explicit (not ksorted) so it is deterministic across processes.
     *
     * @return array<string,string>
     */
    public function toArray(): array
    {
        return [
            'cycleId' => $this->cycleId,
            'prevCycleHash' => $this->prevCycleHash,
            'loopReceiptId' => $this->loopReceiptId,
            'cortexReceiptId' => $this->cortexReceiptId,
            'maestroReceiptId' => $this->maestroReceiptId,
            'factStreamHash' => $this->factStreamHash,
            'auditResultHash' => $this->auditResultHash,
        ];
    }

    /**
     * The hash of THIS entry — the value the NEXT entry stores as its prevCycleHash. Covers every field
     * (including this entry's own prevCycleHash), so the chain is fully tamper-evident.
     */
    public function entryHash(): string
    {
        return hash('sha256', (string) json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string,mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            (string) ($row['cycleId'] ?? ''),
            (string) ($row['prevCycleHash'] ?? ''),
            (string) ($row['loopReceiptId'] ?? ''),
            (string) ($row['cortexReceiptId'] ?? ''),
            (string) ($row['maestroReceiptId'] ?? ''),
            (string) ($row['factStreamHash'] ?? ''),
            (string) ($row['auditResultHash'] ?? ''),
        );
    }
}

/**
 * TRINITY RECEIPT CHAIN — the Evidence-Ledger-style, append-only, tamper-evident proof that the recursive
 * three-way coupling (Loop ⇒ Cortex ⇒ Maestro) actually EXECUTED each claimed cycle. Each cycle appends ONE
 * {@see TrinityReceiptChainEntry} linked to its predecessor by {@see TrinityReceiptChainEntry::$prevCycleHash}
 * (= the prior entry's {@see TrinityReceiptChainEntry::entryHash()}). The chain is verifiable end-to-end:
 * {@see verifyChain()} walks every entry, recomputes each prevCycleHash from the prior entry, and refuses any
 * entry whose link is broken — so a single mutated byte anywhere in the file is detected.
 *
 * Append-only on disk (one JSON object per line, storage/atlas/trinity/receipts/chain.jsonl). Insertion order
 * is the file order, stable across process restarts (a fresh instance over the same file walks identically).
 * A cycleId may appear at most once ({@see TrinityReceiptChainDuplicateCycleException}). Pure facts: nothing is
 * scored, ranked or averaged — the chain only proves "this cycle ran, here are its receipts, here is the link".
 */
final class AtlasLoopTrinityReceiptChain
{
    /** The prevCycleHash of the genesis (first) entry — there is no prior entry to hash. */
    public const GENESIS_PREV_HASH = 'TRINITY_GENESIS';

    private readonly string $chainFile;

    public function __construct(?string $chainFile = null)
    {
        $this->chainFile = $chainFile ?? self::defaultChainFile();
    }

    /**
     * Append one honestly-executed Trinity cycle. Throws {@see TrinityReceiptChainDuplicateCycleException} if the
     * cycleId already has an entry. Returns the persisted entry (with its computed prevCycleHash).
     *
     * @param  object  $audit  a {@see TrinityFeedbackAuditResult} (duck-typed: anything exposing toArray():array,
     *                         so the chain does not couple to that co-located class's autoload)
     */
    public function append(TrinityCycleResult $cycle, object $audit): TrinityReceiptChainEntry
    {
        $entries = $this->readEntries();

        foreach ($entries as $existing) {
            if ($existing->cycleId === $cycle->cycleId) {
                throw new TrinityReceiptChainDuplicateCycleException('Trinity cycle already chained: cycleId='.$cycle->cycleId);
            }
        }

        $last = $entries === [] ? null : $entries[array_key_last($entries)];
        $prevCycleHash = $last === null ? self::GENESIS_PREV_HASH : $last->entryHash();

        $entry = new TrinityReceiptChainEntry(
            $cycle->cycleId,
            $prevCycleHash,
            $cycle->loopReceiptId,
            $cycle->cortexReceiptId,
            $cycle->maestroReceiptId,
            self::hashCanonical($cycle->factStream),
            self::hashCanonical((array) $audit->toArray()),
        );

        $this->persist($entry);

        return $entry;
    }

    /**
     * Walk every entry, recomputing each entry's expected prevCycleHash from the prior entry. Returns false the
     * moment any link is broken (tamper-evident); true for an honest chain (including the empty chain).
     */
    public function verifyChain(): bool
    {
        $expectedPrev = self::GENESIS_PREV_HASH;
        foreach ($this->readEntries() as $entry) {
            if (! hash_equals($expectedPrev, $entry->prevCycleHash)) {
                return false;
            }
            $expectedPrev = $entry->entryHash();
        }

        return true;
    }

    /**
     * The chronologically last appended entry, or null when the chain is empty.
     */
    public function latest(): ?TrinityReceiptChainEntry
    {
        $entries = $this->readEntries();

        return $entries === [] ? null : $entries[array_key_last($entries)];
    }

    /**
     * @return list<TrinityReceiptChainEntry>
     */
    private function readEntries(): array
    {
        if (! is_file($this->chainFile)) {
            return [];
        }

        $entries = [];
        foreach (preg_split('/\R/', (string) file_get_contents($this->chainFile)) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $entries[] = TrinityReceiptChainEntry::fromArray($decoded);
            }
        }

        return $entries;
    }

    private function persist(TrinityReceiptChainEntry $entry): void
    {
        $dir = dirname($this->chainFile);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $line = (string) json_encode($entry->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents($this->chainFile, $line."\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * @param  array<mixed>  $value
     */
    private static function hashCanonical(array $value): string
    {
        return hash('sha256', (string) json_encode(self::canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $isList = array_is_list($value);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::canonicalize($v);
        }
        if (! $isList) {
            ksort($out);
        }

        return $out;
    }

    private static function defaultChainFile(): string
    {
        $base = function_exists('storage_path')
            ? storage_path('atlas/trinity/receipts')
            : sys_get_temp_dir().'/atlas/trinity/receipts';

        return $base.'/chain.jsonl';
    }
}
