<?php

declare(strict_types=1);

namespace App\Services\Ai\Autonomy;

use Carbon\CarbonImmutable;

/**
 * MAXK-06 — sealed JSONL implementation of the metrics authority port.
 *
 * The ladder-promotion caller can no longer hand the gate its numbers: the
 * gate reads them from THIS ledger, and the ledger seals each entry with a
 * SHA-256 over the canonical `{level, metrics, sealed_at, source, source_id}`
 * envelope. On read we recompute the hash from the stored payload and refuse
 * to publish metrics whose recompute does not match the stored `entry_hash`
 * — i.e. any post-seal tampering surfaces as `metrics_authority_tampered`,
 * NOT as silent zeros.
 *
 * The ledger is intentionally file-backed and append-only. `seal()` is the
 * only writer; it is used by ASI-05 telemetry (the production sealer, later
 * slices) and by tests seeding fixtures. `metricsFor()` returns the latest
 * verified entry per level; if no entry exists it returns the sentinel
 * `metrics_authority_missing`, which the gate must refuse.
 *
 * Config: `atlas.ai.autonomy_ladder.metrics_authority_ledger_path` (env
 * `ATLAS_AUTONOMY_LADDER_METRICS_AUTHORITY_LEDGER_PATH`) is env-swappable so
 * phpunit points at a tmp file and the real production ledger is never
 * touched from tests.
 */
final class SealedLedgerAutonomyMetricsAuthority implements AtlasAutonomyMetricsAuthorityPort
{
    public const SCHEMA = 'atlas.autonomy.ladder_metrics_authority.v1';

    public const SOURCE_SEALED = 'atlas_autonomy_metrics_authority';

    public function __construct(private readonly ?string $path = null) {}

    public function metricsFor(string $level): array
    {
        $level = trim($level);
        $latest = null;
        foreach ($this->replay() as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (($row['level'] ?? '') !== $level) {
                continue;
            }
            if (! $this->hasEnvelopeShape($row)) {
                continue;
            }
            $latest = $row;
        }

        if ($latest === null) {
            return $this->missing($level);
        }

        $recomputed = $this->computeEntryHash(
            (string) $latest['level'],
            $this->normaliseMetrics($latest['metrics']),
            (string) $latest['sealed_at'],
            (string) $latest['source'],
            (string) $latest['source_id'],
        );

        if (! hash_equals((string) $latest['entry_hash'], $recomputed)) {
            return $this->tampered($level, $latest);
        }

        return [
            'metrics' => $this->normaliseMetrics($latest['metrics']),
            'provenance' => [
                'source' => (string) $latest['source'],
                'source_id' => (string) $latest['source_id'],
                'sealed_at' => (string) $latest['sealed_at'],
                'entry_hash' => (string) $latest['entry_hash'],
                'verified' => true,
            ],
        ];
    }

    /**
     * Seal a metric map for a target level. Only called by the sealer
     * (ASI-05 telemetry or a test fixture) — the promotion caller must
     * never reach this method. Returns the appended entry.
     *
     * @param  array<string,float|int>  $metrics
     * @return array<string,mixed>
     */
    public function seal(
        string $level,
        array $metrics,
        string $sourceId,
        ?string $sealedAt = null,
        string $source = self::SOURCE_SEALED,
    ): array {
        $sealedAt ??= CarbonImmutable::now('UTC')->toIso8601String();
        $normalised = $this->normaliseMetrics($metrics);
        $entryHash = $this->computeEntryHash($level, $normalised, $sealedAt, $source, $sourceId);

        $payload = [
            'schema_version' => self::SCHEMA,
            'level' => $level,
            'metrics' => $normalised,
            'source' => $source,
            'source_id' => $sourceId,
            'sealed_at' => $sealedAt,
            'entry_hash' => $entryHash,
        ];

        $this->append($payload);

        return $payload;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function replay(): array
    {
        $path = $this->resolvePath();
        if ($path === '' || ! is_file($path)) {
            return [];
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }
        $rows = [];
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
     * @param  array<string,mixed>  $row
     */
    private function hasEnvelopeShape(array $row): bool
    {
        foreach (['level', 'metrics', 'source', 'source_id', 'sealed_at', 'entry_hash'] as $required) {
            if (! array_key_exists($required, $row)) {
                return false;
            }
        }

        return is_array($row['metrics']);
    }

    /**
     * @param  mixed  $metrics
     * @return array<string,float>
     */
    private function normaliseMetrics($metrics): array
    {
        if (! is_array($metrics)) {
            return [];
        }
        $out = [];
        foreach ($metrics as $key => $value) {
            if (! is_string($key) || ! is_numeric($value)) {
                continue;
            }
            $out[$key] = (float) $value;
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /**
     * Canonical entry hash: SHA-256 over the JSON-canonical envelope. Metric
     * keys are sorted deterministically to prevent trivial "resort" tampering.
     *
     * @param  array<string,float>  $metrics
     */
    private function computeEntryHash(
        string $level,
        array $metrics,
        string $sealedAt,
        string $source,
        string $sourceId,
    ): string {
        $envelope = [
            'schema_version' => self::SCHEMA,
            'level' => $level,
            'metrics' => $metrics,
            'source' => $source,
            'source_id' => $sourceId,
            'sealed_at' => $sealedAt,
        ];

        $canonical = json_encode(
            $envelope,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );

        return hash('sha256', is_string($canonical) ? $canonical : '');
    }

    /**
     * @return array{metrics: array<string,float>, provenance: array<string,mixed>}
     */
    private function missing(string $level): array
    {
        return [
            'metrics' => [],
            'provenance' => [
                'source' => self::SOURCE_MISSING,
                'source_id' => $level,
                'sealed_at' => '',
                'entry_hash' => '',
                'verified' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $latest
     * @return array{metrics: array<string,float>, provenance: array<string,mixed>}
     */
    private function tampered(string $level, array $latest): array
    {
        return [
            'metrics' => [],
            'provenance' => [
                'source' => self::SOURCE_TAMPERED,
                'source_id' => (string) ($latest['source_id'] ?? $level),
                'sealed_at' => (string) ($latest['sealed_at'] ?? ''),
                'entry_hash' => (string) ($latest['entry_hash'] ?? ''),
                'verified' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function append(array $payload): void
    {
        $path = $this->resolvePath();
        if ($path === '') {
            return;
        }
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $line = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
        if (! is_string($line)) {
            return;
        }
        @file_put_contents($path, $line."\n", FILE_APPEND | LOCK_EX);
    }

    private function resolvePath(): string
    {
        return $this->path
            ?? (string) config('atlas.ai.autonomy_ladder.metrics_authority_ledger_path', '');
    }
}
