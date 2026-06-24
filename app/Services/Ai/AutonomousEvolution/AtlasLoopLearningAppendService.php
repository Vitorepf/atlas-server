<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * APRENDER (phase 8 — the closing phase of the canonical 8-phase live cycle). It appends ONE raw FACT row per
 * CLOSED cycle, joining the three deterministic fact streams (orientation snapshot, leverage decision,
 * decomposition plan) to the executed obra's terminal certified-vs-thrashed envelope — so downstream priors
 * (e.g. AtlasLoopDecompositionShapePrior's Wilson lower-bound) can ground against real closed-cycle facts.
 *
 * APPEND-ONLY + idempotent by cycle_id (a re-run of the same cycle never duplicates a row). The public surface
 * is append()/entries() ONLY — there is deliberately NO update/delete (prior rows are immutable).
 *
 * ANTI-GOODHART (the no-scalar contract inherited from AtlasLoopLeverageSelector / AtlasLoopComprehensionOriginator):
 * it stores ONLY raw facts — NEVER a learning score, quality scalar, or leverage rank. Flag
 * atlas.loop.learning_append_enabled default OFF ⇒ append() returns null and writes nothing.
 */
final class AtlasLoopLearningAppendService
{
    public const SCHEMA = 'atlas.loop.learning_row.v1';

    private readonly string $ledgerPath;

    public function __construct(?string $ledgerPath = null)
    {
        $this->ledgerPath = $ledgerPath ?? storage_path('atlas-loop/learning/cycle-ledger.ndjson');
    }

    /**
     * Append ONE learning row for a closed cycle. Idempotent by cycle_id. Returns the row, or null (flag OFF /
     * empty cycle_id / unwritable).
     *
     * @param  array<string,mixed>  $orientation    the phase-1 orientation fact snapshot
     * @param  array<string,mixed>  $leverage       the phase-3 leverage decision envelope
     * @param  array<string,mixed>  $decomposition  the phase-5 decomposition plan
     * @param  array<string,mixed>  $terminal       the executed obra's terminal envelope (certified / thrashed)
     * @return array<string,mixed>|null
     */
    public function append(string $cycleId, array $orientation, array $leverage, array $decomposition, array $terminal): ?array
    {
        if (! (bool) config('atlas.loop.learning_append_enabled', false)) {
            return null; // flag OFF ⇒ byte-identical no-op
        }
        $cycleId = trim($cycleId);
        if ($cycleId === '') {
            return null;
        }

        $row = [
            'schema_version' => self::SCHEMA,
            'cycle_id' => $cycleId,
            'orientation_fact_hash' => $this->factHash($orientation),
            'leverage_decision_hash' => $this->factHash($leverage),
            'decomposition_plan_fingerprint' => trim((string) ($decomposition['plan_fingerprint'] ?? '')) ?: $this->factHash($decomposition),
            'terminal_certified' => ($terminal['certified'] ?? false) === true,
            'terminal_reason' => (string) ($terminal['terminal_reason'] ?? ($terminal['reason'] ?? '')),
            'appended_at' => Carbon::now('UTC')->toIso8601String(),
        ];

        if ($this->hasCycle($cycleId)) {
            return $row; // idempotent — the cycle is already recorded; never duplicate
        }

        try {
            $dir = dirname($this->ledgerPath);
            if (! is_dir($dir) && ! @mkdir($dir, 0o775, true) && ! is_dir($dir)) {
                return null;
            }
            @file_put_contents($this->ledgerPath, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            return null;
        }

        return $row;
    }

    /**
     * The recorded learning rows in insertion order.
     *
     * @return list<array<string,mixed>>
     */
    public function entries(): array
    {
        if (! is_file($this->ledgerPath)) {
            return [];
        }
        $out = [];
        foreach (preg_split('/\R/', (string) @file_get_contents($this->ledgerPath)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    private function hasCycle(string $cycleId): bool
    {
        foreach ($this->entries() as $row) {
            if ((string) ($row['cycle_id'] ?? '') === $cycleId) {
                return true;
            }
        }

        return false;
    }

    /** Deterministic content hash of a fact array (recursively key-sorted), for provenance joins. */
    private function factHash(array $data): string
    {
        return substr(hash('sha256', (string) json_encode($this->canonicalize($data), JSON_UNESCAPED_SLASHES)), 0, 40);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $isList = array_is_list($value);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = $this->canonicalize($v);
        }
        if (! $isList) {
            ksort($out);
        }

        return $out;
    }
}
