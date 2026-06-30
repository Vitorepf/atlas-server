<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Autonomy;

/**
 * Append-only JSONL ledger for Self-Construction autonomy transitions.
 *
 * Event kinds:
 *   - requested_transition — a transition was requested for evaluation
 *   - promoted             — promotion gate accepted the transition
 *   - degraded             — degradation policy lowered the autonomy level
 *   - refused              — gate or policy refused the transition
 *
 * Every event row carries: schema_version, kind, level, decision, reasons, lane,
 * evidence_hash (deterministic over the canonical event body), created_at_unix.
 *
 * INVARIANTS:
 *   - The file is opened with O_APPEND semantics — events are NEVER mutated or rewritten.
 *   - evidence_hash is sha256 over a canonical JSON form (sorted keys) of the event body.
 *   - Querying never mutates the underlying file.
 *
 * Pure deterministic helpers; no providers, no network.
 */
final class AtlasSelfConstructionAutonomyRuntimeLedger
{
    public const SCHEMA = 'atlas.self_construction.autonomy_runtime_ledger.v1';

    public const KIND_REQUESTED = 'requested_transition';
    public const KIND_PROMOTED = 'promoted';
    public const KIND_DEGRADED = 'degraded';
    public const KIND_REFUSED = 'refused';

    public function __construct(private readonly string $ledgerPath) {}

    /**
     * Append one event. Returns the written row (with computed evidence_hash).
     *
     * @param  array<string,mixed>  $event {kind, level, decision, reasons, lane, created_at_unix}
     * @return array<string,mixed>
     */
    public function append(array $event): array
    {
        $row = $this->normalize($event);
        $this->writeRow($row);

        return $row;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function writeRow(array $row): void
    {
        $dir = \dirname($this->ledgerPath);
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new \RuntimeException('autonomy_ledger_mkdir_failed:'.$dir);
        }
        $line = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        // O_APPEND atomic on POSIX writes <= PIPE_BUF; well within our line size.
        $fh = @fopen($this->ledgerPath, 'ab');
        if ($fh === false) {
            throw new \RuntimeException('autonomy_ledger_open_failed:'.$this->ledgerPath);
        }
        try {
            $written = fwrite($fh, $line);
            if ($written === false || $written !== strlen($line)) {
                throw new \RuntimeException('autonomy_ledger_write_failed:'.$this->ledgerPath);
            }
        } finally {
            fclose($fh);
        }
    }

    /**
     * Append only if no row with the same evidence_hash exists. Returns the row on first write,
     * or the existing matching row on duplicate (no-write).
     *
     * @param  array<string,mixed>  $event
     * @return array{row:array<string,mixed>, appended:bool}
     */
    public function appendIfNew(array $event): array
    {
        $row = $this->normalize($event);
        foreach ($this->all() as $existing) {
            if (($existing['evidence_hash'] ?? '') === $row['evidence_hash']) {
                return ['row' => $existing, 'appended' => false];
            }
        }
        $this->writeRow($row);

        return ['row' => $row, 'appended' => true];
    }

    /**
     * Return the most recent $n events, oldest-first. Never mutates the file.
     *
     * @return list<array<string,mixed>>
     */
    public function tail(int $n): array
    {
        $all = $this->all();

        return array_values(array_slice($all, max(0, count($all) - $n)));
    }

    /**
     * Return every event row, oldest-first. Read-only.
     *
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        if (! is_file($this->ledgerPath)) {
            return [];
        }
        $rows = [];
        $fh = @fopen($this->ledgerPath, 'rb');
        if ($fh === false) {
            return [];
        }
        try {
            while (($line = fgets($fh)) !== false) {
                $line = rtrim($line, "\n");
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $rows[] = $decoded;
                }
            }
        } finally {
            fclose($fh);
        }

        return $rows;
    }

    /**
     * Return the most recent event, or null when the ledger is empty.
     *
     * @return array<string,mixed>|null
     */
    public function latest(): ?array
    {
        $rows = $this->all();
        if ($rows === []) {
            return null;
        }

        return $rows[count($rows) - 1];
    }

    /**
     * Return events filtered by lane, oldest-first.
     *
     * @return list<array<string,mixed>>
     */
    public function historyForLane(string $lane): array
    {
        return array_values(array_filter($this->all(), static fn (array $r): bool => (string) ($r['lane'] ?? '') === $lane));
    }

    /**
     * @param  array<string,mixed>  $event
     * @return array<string,mixed>
     */
    private function normalize(array $event): array
    {
        $kind = (string) ($event['kind'] ?? '');
        if (! in_array($kind, [self::KIND_REQUESTED, self::KIND_PROMOTED, self::KIND_DEGRADED, self::KIND_REFUSED], true)) {
            throw new \InvalidArgumentException('autonomy_ledger_unknown_kind:'.$kind);
        }

        $createdAtUnix = (int) ($event['created_at_unix'] ?? 0);
        $body = [
            'schema_version' => self::SCHEMA,
            'kind' => $kind,
            'level' => (string) ($event['level'] ?? ''),
            'decision' => (string) ($event['decision'] ?? ''),
            'reasons' => array_values(array_map('strval', (array) ($event['reasons'] ?? []))),
            'lane' => (string) ($event['lane'] ?? ''),
            'created_at_unix' => $createdAtUnix,
            'created_at' => $createdAtUnix > 0 ? gmdate('Y-m-d\TH:i:s\Z', $createdAtUnix) : '',
        ];
        $body['evidence_hash'] = $this->hashBody($body);

        return $body;
    }

    /**
     * @param  array<string,mixed>  $body
     */
    private function hashBody(array $body): string
    {
        $canonical = $body;
        unset($canonical['evidence_hash']);
        ksort($canonical);
        if (isset($canonical['reasons']) && is_array($canonical['reasons'])) {
            sort($canonical['reasons'], SORT_STRING);
        }

        return hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
