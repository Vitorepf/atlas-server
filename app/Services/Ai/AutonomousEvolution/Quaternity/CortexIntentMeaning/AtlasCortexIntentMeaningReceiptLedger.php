<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Quaternity\CortexIntentMeaning;

use Carbon\CarbonImmutable;
use Closure;
use Throwable;

/**
 * QUATERNITY · CORTEX-INTENT-MEANING — the operator-as-primitive RECEIPT. Every time Cortex grounds an operator
 * intent (triangulation + ambiguity verdict), this append-only JSON-Lines ledger persists ONE byte-stable
 * receipt to disk. Pure PHP — no DB, no Eloquent, no provider — so the loop stays auditable on the local Mac
 * with zero external infrastructure.
 *
 * Invariants: append-only (prior lines are never rewritten); byte-stable (sorted keys + canonical JSON, so
 * equal inputs under a fixed clock produce identical bytes); resilient ({@see list()} skips a malformed line
 * instead of crashing the loop). The clock is injectable for deterministic tests.
 */
final class AtlasCortexIntentMeaningReceiptLedger
{
    private const RELATIVE_PATH = 'atlas/loop/cortex-intent-meaning-receipts.jsonl';

    private ?Closure $clock = null;

    public function __construct(private readonly ?string $path = null) {}

    /** Test seam: pin the clock so recorded_at is deterministic. */
    public function setClock(Closure $clock): void
    {
        $this->clock = $clock;
    }

    public function path(): string
    {
        return $this->path ?? storage_path(self::RELATIVE_PATH);
    }

    /**
     * Append ONE receipt for a grounding outcome and return exactly what was written.
     *
     * @param  array<int,mixed>  $triangulationFacts
     * @param  array<string,mixed>  $ambiguityVerdict
     * @return array<string,mixed>
     */
    public function record(string $intentText, array $triangulationFacts, array $ambiguityVerdict): array
    {
        $receipt = $this->canonicalize([
            'recorded_at' => $this->now()->toIso8601String(),
            'intent' => $intentText,
            'site_count' => (int) ($ambiguityVerdict['site_count'] ?? 0),
            'ambiguous' => (bool) ($ambiguityVerdict['ambiguous'] ?? false),
            'clarification_required' => (bool) ($ambiguityVerdict['clarification_required'] ?? false),
            'reason' => (string) ($ambiguityVerdict['reason'] ?? ''),
            'distinct_symbols' => array_values(array_map('strval', (array) ($ambiguityVerdict['distinct_symbols'] ?? []))),
            'distinct_files' => array_values(array_map('strval', (array) ($ambiguityVerdict['distinct_files'] ?? []))),
            'fact_digest' => sha1($this->canonicalJson($triangulationFacts)),
        ]);

        $this->appendLine($this->canonicalJson($receipt));

        return $receipt;
    }

    /**
     * The most recent receipts in append order. A malformed line is skipped, never fatal.
     *
     * @return list<array<string,mixed>>
     */
    public function list(int $limit = 50): array
    {
        $path = $this->path();
        if (! is_file($path)) {
            return [];
        }

        $rows = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            try {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                continue; // resilience: a corrupted line never crashes the loop
            }
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $limit > 0 ? array_slice($rows, -$limit) : $rows;
    }

    private function appendLine(string $line): void
    {
        $path = $this->path();
        $dir = \dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $fp = fopen($path, 'ab');
        if ($fp === false) {
            return; // best-effort: a receipt write must not crash the loop
        }
        try {
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, $line.PHP_EOL);
                fflush($fp);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }
    }

    private function now(): CarbonImmutable
    {
        if ($this->clock !== null) {
            return CarbonImmutable::instance(($this->clock)())->utc();
        }

        return CarbonImmutable::now('UTC');
    }

    /**
     * Byte-stable JSON: keys sorted recursively (lists keep order), JSON_THROW, no whitespace drift.
     */
    private function canonicalJson(mixed $value): string
    {
        return (string) json_encode(
            $this->canonicalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $v): mixed => $this->canonicalize($v), $value);
        }
        ksort($value);
        foreach ($value as $key => $child) {
            $value[$key] = $this->canonicalize($child);
        }

        return $value;
    }
}
