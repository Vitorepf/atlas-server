<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * WIRING-INTENT LEDGER — the append-only, persistent history of every wiring-intent the Multi-Site Wiring
 * Planner emits: which primitive was planned to light which consumer, when, at what status, against which
 * snapshot. It closes the moat-of-decisions gap (primitivos PARKED 0-prod in loop-architecture-debt-wiring-gap)
 * and mirrors the AtlasLoopAttemptLedger pattern.
 *
 * APPEND-ONLY: a record is one JSON line; prior lines are NEVER mutated. PÉTREO: the ledger makes NO wiring
 * decision — it only records what the planner already produced. ANTI-GOODHART: it stores RAW facts only (no
 * count, no ratio, no aggregate the Armed Coverage Reporter could shortcut). Flag
 * ATLAS_LOOP_WIRING_INTENT_LEDGER_ENABLED default OFF ⇒ record() returns null and read() returns [] (the file
 * is never even created). Fail-closed: an unwritable target ⇒ record() returns null without throwing.
 */
final class AtlasLoopWiringIntentLedger
{
    public const SCHEMA = 'atlas.loop.wiring_intent_ledger.v1';

    private readonly string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? storage_path('app/atlas/loop/wiring-intent-ledger.jsonl');
    }

    /**
     * Append ONE wiring-intent record. Returns the stored row, or null when disabled / unwritable (fail-closed).
     *
     * @param  array<string,mixed>  $intent  a planner-shaped record {primitive_id, consumer_path, seam_anchor, status, snapshot_sha?}
     * @return array<string,mixed>|null
     */
    public function record(array $intent): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $row = [
            'schema' => self::SCHEMA,
            'ts' => Carbon::now('UTC')->toIso8601String(),
            'primitive_id' => (string) ($intent['primitive_id'] ?? ''),
            'consumer_path' => (string) ($intent['consumer_path'] ?? ''),
            'seam_anchor' => (string) ($intent['seam_anchor'] ?? ''),
            'status' => (string) ($intent['status'] ?? ''),
            'snapshot_sha' => (string) ($intent['snapshot_sha'] ?? ''),
        ];

        try {
            $dir = dirname($this->path);
            if (! is_dir($dir) && ! @mkdir($dir, 0o775, true) && ! is_dir($dir)) {
                return null; // fail-closed: cannot create the target directory
            }
            $written = @file_put_contents(
                $this->path,
                json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL,
                FILE_APPEND | LOCK_EX,
            );

            return $written === false ? null : $row;
        } catch (Throwable) {
            return null; // fail-closed — a ledger write must never break the caller
        }
    }

    /**
     * The recorded intents in insertion order, or [] when disabled / no file.
     *
     * @return list<array<string,mixed>>
     */
    public function read(): array
    {
        if (! $this->enabled() || ! is_file($this->path)) {
            return [];
        }

        $out = [];
        foreach (preg_split('/\R/', (string) @file_get_contents($this->path)) ?: [] as $line) {
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

    private function enabled(): bool
    {
        return (bool) config('atlas.loop.wiring_intent_ledger_enabled', false);
    }
}
