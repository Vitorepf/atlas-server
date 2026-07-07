<?php

declare(strict_types=1);

namespace App\Services\Ai\Brain;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * DIARIO (Carta de Autonomia, Regra 3) — the Evolution Diary.
 *
 * Every autonomous act the Atlas takes on its own — a promoted memory, a
 * corrected context, a built organ, a distilled lesson, an applied refactor, a
 * graduated automation, a retired organ — writes ONE labelled entry here, in
 * the same act. This is what REPLACES human approval: the operator does not
 * approve beforehand; they navigate the Diary afterwards and revert the rare
 * thing they dislike.
 *
 * Storage is an on-DISK append-only hash-chained JSONL — NOT the database —
 * for the same reason as the SIS8 memory journal: the Diary is the record of
 * autonomy and must survive the very DROP DATABASE that SIS8 protects against.
 * A DB-backed Diary would vanish with the brain it documents.
 *
 * Entry shape (one JSON object per line):
 *   {"seq":N,"id":"ulid","data":"ISO-8601","tipo":"merge|...","o_que":"...",
 *    "por_que":"...","evidencia":"commit/receipt/test ref|null",
 *    "id_reversao":"git sha | memory:<seq> | null",
 *    "prev_hash":"sha256|GENESIS","line_hash":"sha256"}
 *
 * ponytail: shares the hash-chain mechanics (canonicalize/hash/verifyChain)
 * with {@see AtlasMemoryJournal}. Kept as a focused sibling rather than a shared
 * base to avoid re-touching the just-shipped SIS8 file; fold both onto one
 * primitive when a third disk-ledger appears (a JsonlLedgerTrait is already
 * trending in the tree).
 */
class AtlasEvolutionDiary
{
    public const GENESIS = 'GENESIS';

    /** The labels an autonomous evolution can carry (Carta Regra 3 + #17/#18). */
    public const TIPOS = [
        'promocao-memoria',
        'autocorrecao-contexto',
        'merge',
        'orgao-novo',
        'graduacao',
        'aposentadoria',
        'refatoracao',
        'licao',
        'decisao-fabrica',
        'evolucao-de-fase',
    ];

    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? (string) config(
            'atlas.brain.diary.path',
            storage_path('atlas/evolution-diary/diary.jsonl'),
        );
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Append one labelled evolution. Called in the SAME act that performs it.
     *
     * @return array<string,mixed> the appended entry (incl. id + line_hash)
     */
    public function record(
        string $tipo,
        string $oQue,
        string $porQue,
        ?string $evidencia = null,
        ?string $idReversao = null,
    ): array {
        if (! in_array($tipo, self::TIPOS, true)) {
            throw new InvalidArgumentException(
                "Unknown evolution tipo '{$tipo}'. One of: ".implode(', ', self::TIPOS),
            );
        }

        $dir = dirname($this->path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $last = $this->lastRecord();
        $entry = [
            'seq' => (int) ($last['seq'] ?? 0) + 1,
            'id' => strtolower((string) Str::ulid()),
            'data' => now()->toISOString(),
            'tipo' => $tipo,
            'o_que' => trim($oQue),
            'por_que' => trim($porQue),
            'evidencia' => $evidencia !== null ? trim($evidencia) : null,
            'id_reversao' => $idReversao !== null ? trim($idReversao) : null,
            'prev_hash' => (string) ($last['line_hash'] ?? self::GENESIS),
        ];
        $entry['line_hash'] = self::hash($entry);

        file_put_contents(
            $this->path,
            json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );

        return $entry;
    }

    /**
     * @return array<int,array<string,mixed>> all entries, newest last
     */
    public function all(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        $out = [];
        foreach (file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            try {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                continue;
            }
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    /**
     * Entries recorded today (operator's local day), grouped by tipo.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    public function today(): array
    {
        $tz = (string) config('app.timezone', 'UTC');
        $today = now()->toDateString();
        $grouped = [];
        foreach ($this->all() as $entry) {
            $data = (string) ($entry['data'] ?? '');
            if ($data === '') {
                continue;
            }
            // `data` is stored UTC-ISO; compare on the operator's local day so
            // "hoje" means today for a non-UTC operator, not today in UTC.
            if (CarbonImmutable::parse($data)->timezone($tz)->toDateString() === $today) {
                $grouped[(string) ($entry['tipo'] ?? 'desconhecido')][] = $entry;
            }
        }

        return $grouped;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function filter(?string $tipo = null, ?string $desde = null): array
    {
        return array_values(array_filter($this->all(), static function (array $entry) use ($tipo, $desde): bool {
            if ($tipo !== null && ($entry['tipo'] ?? null) !== $tipo) {
                return false;
            }
            if ($desde !== null && substr((string) ($entry['data'] ?? ''), 0, 10) < $desde) {
                return false;
            }

            return true;
        }));
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(string $id): ?array
    {
        $id = strtolower(trim($id));
        foreach ($this->all() as $entry) {
            if (strtolower((string) ($entry['id'] ?? '')) === $id) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Walk the chain end to end (tamper/gap detection).
     *
     * @return array{ok:bool,count:int,broken_at:?int,reason:?string}
     */
    public function verifyChain(): array
    {
        $prev = self::GENESIS;
        $count = 0;

        foreach ($this->all() as $entry) {
            $count++;
            if (! isset($entry['line_hash'], $entry['prev_hash'])) {
                return ['ok' => false, 'count' => $count, 'broken_at' => $count, 'reason' => 'corrupt_line'];
            }
            $recomputed = self::hash(array_diff_key($entry, ['line_hash' => true]));
            if ($entry['prev_hash'] !== $prev) {
                return ['ok' => false, 'count' => $count, 'broken_at' => (int) ($entry['seq'] ?? $count), 'reason' => 'prev_hash_mismatch'];
            }
            if ($entry['line_hash'] !== $recomputed) {
                return ['ok' => false, 'count' => $count, 'broken_at' => (int) ($entry['seq'] ?? $count), 'reason' => 'line_hash_mismatch'];
            }
            $prev = $entry['line_hash'];
        }

        return ['ok' => true, 'count' => $count, 'broken_at' => null, 'reason' => null];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function lastRecord(): ?array
    {
        $all = $this->all();

        return $all === [] ? null : (array) end($all);
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
     * @param  array<string,mixed>  $entry
     */
    private static function hash(array $entry): string
    {
        return hash('sha256', json_encode(
            self::canonicalize($entry),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }
}
