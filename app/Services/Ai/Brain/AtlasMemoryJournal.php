<?php

declare(strict_types=1);

namespace App\Services\Ai\Brain;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use Throwable;

/**
 * SIS8 (Obra #20) — journal-first reversibility for the brain.
 *
 * Every memory mutation appends a hash-chained line to an on-DISK JSONL — NOT
 * the database. That is the whole point: the journal must survive a
 * `DROP DATABASE` so `atlas:brain:replay` can rebuild the memory rows from it
 * ("Postgres vira gado"). It reuses the {@see AtlasEvidenceLedger}
 * discipline (recursive canonicalisation + sha256), but the Evidence Ledger is
 * DB-backed and does not cover memory — so this is a sibling, not a caller.
 *
 * Reversibility is the license the Carta de Autonomia trades for human
 * approval: the Atlas may act on its own precisely because every act is
 * replayable.
 *
 * Line shape (one JSON object per line):
 *   {"seq":N,"ts":"ISO-8601","op":"record|upsert|curate",
 *    "table":"atlas_memory_entries","id":"uuid","attributes":{...},
 *    "prev_hash":"sha256|GENESIS","line_hash":"sha256"}
 *
 * line_hash = sha256(canonical(record without line_hash)). Because each line
 * carries the previous line's hash, any tamper or gap is detectable on replay.
 */
class AtlasMemoryJournal
{
    public const GENESIS = 'GENESIS';

    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? (string) config(
            'atlas.brain.journal.path',
            storage_path('atlas/brain-journal/memory.jsonl'),
        );
    }

    public function path(): string
    {
        return $this->path;
    }

    public function enabled(): bool
    {
        return (bool) config('atlas.brain.journal.enabled', true);
    }

    /**
     * Append a mutation line. The caller writes here as the authoritative
     * record; the DB row is downstream cattle.
     *
     * @param  array<string,mixed>  $attributes  full final row snapshot
     * @return array<string,mixed> the appended record (incl. line_hash)
     */
    public function append(string $op, string $table, string $id, array $attributes): array
    {
        $dir = dirname($this->path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        // ponytail: O(n) tail-read per append (whole file). Fine at brain write
        // volume (~0.25 organic writes/day, bursts of tens); switch to a
        // seek-from-end tail or a sidecar head-pointer if the journal ever
        // grows past ~10^5 lines.
        $last = $this->lastRecord();
        $prevHash = (string) ($last['line_hash'] ?? self::GENESIS);
        $seq = (int) ($last['seq'] ?? 0) + 1;

        $record = [
            'seq' => $seq,
            'ts' => now()->toISOString(),
            'op' => $op,
            'table' => $table,
            'id' => $id,
            'attributes' => self::canonicalize($attributes),
            'prev_hash' => $prevHash,
        ];
        $record['line_hash'] = self::hash($record);

        // LOCK_EX serialises concurrent appends (one writer at a time), matching
        // the Carta's "serialização invisível" for the shared local main.
        file_put_contents(
            $this->path,
            json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );

        return $record;
    }

    /**
     * @return array<int,array<string,mixed>> all records in append order
     */
    public function read(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        $out = [];
        foreach (file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            try {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                // A corrupt line is surfaced by verifyChain(), not silently here.
                $out[] = ['__corrupt__' => $line];

                continue;
            }
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    /**
     * Walk the chain end to end.
     *
     * @return array{ok:bool,count:int,broken_at:?int,reason:?string}
     */
    public function verifyChain(): array
    {
        $prev = self::GENESIS;
        $count = 0;

        foreach ($this->read() as $record) {
            $count++;

            if (isset($record['__corrupt__']) || ! isset($record['line_hash'], $record['prev_hash'])) {
                return ['ok' => false, 'count' => $count, 'broken_at' => $count, 'reason' => 'corrupt_line'];
            }

            $recomputed = self::hash(array_diff_key($record, ['line_hash' => true]));

            if ($record['prev_hash'] !== $prev) {
                return ['ok' => false, 'count' => $count, 'broken_at' => (int) ($record['seq'] ?? $count), 'reason' => 'prev_hash_mismatch'];
            }
            if ($record['line_hash'] !== $recomputed) {
                return ['ok' => false, 'count' => $count, 'broken_at' => (int) ($record['seq'] ?? $count), 'reason' => 'line_hash_mismatch'];
            }

            $prev = $record['line_hash'];
        }

        return ['ok' => true, 'count' => $count, 'broken_at' => null, 'reason' => null];
    }

    /**
     * Content digest over reconstructed rows — the kill-test's equality check.
     * Excludes volatile framework timestamps so the comparison proves DATA
     * faithfulness, not row-write wall-clock.
     *
     * @param  iterable<int,AtlasMemoryEntry>  $rows
     */
    public static function rowsDigest(iterable $rows): string
    {
        $snapshots = [];
        foreach ($rows as $row) {
            $attrs = $row->attributesToArray();
            unset($attrs['created_at'], $attrs['updated_at'], $attrs['deleted_at']);
            $snapshots[(string) $row->getKey()] = $attrs;
        }
        ksort($snapshots);

        return hash('sha256', json_encode(
            self::canonicalize($snapshots),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function lastRecord(): ?array
    {
        $records = $this->read();

        return $records === [] ? null : (array) end($records);
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        ksort($value);
        foreach ($value as $key => $inner) {
            $value[$key] = self::canonicalize($inner);
        }

        return $value;
    }

    /**
     * @param  array<string,mixed>  $record
     */
    private static function hash(array $record): string
    {
        return hash('sha256', json_encode(
            self::canonicalize($record),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }
}
