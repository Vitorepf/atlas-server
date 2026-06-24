<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\Support\AppendOnlyJsonlStore;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * §W40 · PROVIDER-HEALTH PROBE — the EYES of substrate sovereignty. Every grind that reaches a provider
 * appends ONE per-provider outcome FACT (latency, cost, ok/error) to a versioned JSONL ledger; the downstream
 * swap policy, fleet autotuner, and effort policy all read {@see snapshot()} to decide. This class only
 * OBSERVES: pure facts, no score, no recommendation, no action.
 *
 * Fail-SAFE like {@see AtlasLoopProviderCircuitBreaker}: a storage error never breaks a grind (Throwable is
 * swallowed). Flag-gated at the source ({@see config()} `atlas.loop.provider_health_probe_enabled`,
 * default-false) ⇒ byte-identical OFF: when disabled, `record()` writes NOTHING.
 *
 * Ledger layout: one append-only `<provider>.jsonl` per provider under
 * `storage/app/atlas/loop/provider-health/`, schema `atlas.loop.provider_health.v1`.
 */
final class AtlasLoopProviderHealthProbe
{
    public const SCHEMA_VERSION = 'atlas.loop.provider_health.v1';

    private const STORAGE_SUBPATH = 'app/atlas/loop/provider-health';

    private ?string $storageRootOverride = null;

    private ?Closure $clock = null;

    /** Test seam: redirect the ledger root to a throwaway directory. */
    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
    }

    /** Test seam: pin the clock so recorded_at + window math are deterministic. */
    public function setClockForTesting(callable $clock): void
    {
        $this->clock = $clock instanceof Closure ? $clock : Closure::fromCallable($clock);
    }

    /**
     * Append ONE provider-health sample. Best-effort + flag-gated: OFF ⇒ no write (byte-identical). Deduped per
     * grind id — calling twice with the same non-empty grind_id appends only once.
     *
     * @param  array<string,mixed>  $sample  expects ok(bool), latency_ms(int), cost_cents(int|null), grind_id(string)
     */
    public function record(string $providerKey, ?string $model, array $sample): void
    {
        if (! (bool) config('atlas.loop.provider_health_probe_enabled', false)) {
            return; // default-OFF ⇒ no JSONL writes ⇒ byte-identical
        }

        $providerKey = trim($providerKey);
        if ($providerKey === '') {
            return;
        }

        try {
            $path = $this->path($providerKey);
            $grindId = trim((string) ($sample['grind_id'] ?? ''));
            if ($grindId !== '' && $this->grindAlreadyRecorded($path, $grindId)) {
                return; // dedup per grind id
            }

            AppendOnlyJsonlStore::append($path, [
                'schema_version' => self::SCHEMA_VERSION,
                'provider_key' => $providerKey,
                'model' => $model !== null && trim($model) !== '' ? trim($model) : null,
                'ok' => (bool) ($sample['ok'] ?? false),
                'latency_ms' => max(0, (int) ($sample['latency_ms'] ?? 0)),
                'cost_cents' => isset($sample['cost_cents']) && is_numeric($sample['cost_cents']) ? (int) $sample['cost_cents'] : null,
                'recorded_at_iso8601' => $this->now()->format(DateTimeInterface::ATOM),
                'grind_id' => $grindId,
            ]);
        } catch (Throwable) {
            // fail-safe: a provider-health write must NEVER break a grind
        }
    }

    /**
     * Window snapshot for one provider: distribution + counts + cost over the last $windowSeconds. Pure read.
     *
     * @return array{schema_version:string, provider_key:string, window_seconds:int, sample_count:int, p50_ms:int, p95_ms:int, ok_count:int, error_count:int, cost_cents_sum:int}
     */
    public function snapshot(string $providerKey, int $windowSeconds): array
    {
        $providerKey = trim($providerKey);
        $windowSeconds = max(1, $windowSeconds);

        try {
            $rows = AppendOnlyJsonlStore::read($this->path($providerKey));
        } catch (Throwable) {
            $rows = [];
        }

        $cutoff = $this->now()->getTimestamp() - $windowSeconds;
        $latencies = [];
        $okCount = 0;
        $errorCount = 0;
        $costSum = 0;
        foreach ($rows as $row) {
            $ts = strtotime((string) ($row['recorded_at_iso8601'] ?? ''));
            if ($ts === false || $ts < $cutoff) {
                continue;
            }
            if (($row['ok'] ?? false) === true) {
                $okCount++;
            } else {
                $errorCount++;
            }
            $latencies[] = max(0, (int) ($row['latency_ms'] ?? 0));
            if (isset($row['cost_cents']) && is_numeric($row['cost_cents'])) {
                $costSum += (int) $row['cost_cents'];
            }
        }
        sort($latencies, SORT_NUMERIC);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'provider_key' => $providerKey,
            'window_seconds' => $windowSeconds,
            'sample_count' => count($latencies),
            'p50_ms' => $this->percentile($latencies, 50),
            'p95_ms' => $this->percentile($latencies, 95),
            'ok_count' => $okCount,
            'error_count' => $errorCount,
            'cost_cents_sum' => $costSum,
        ];
    }

    /** Absolute path of a provider's append-only ledger file. */
    public function path(string $providerKey): string
    {
        $root = $this->storageRootOverride
            ?? (function_exists('storage_path')
                ? storage_path(self::STORAGE_SUBPATH)
                : sys_get_temp_dir().'/'.self::STORAGE_SUBPATH);
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', trim($providerKey)) ?: 'unknown';

        return rtrim($root, '/').'/'.$safe.'.jsonl';
    }

    private function grindAlreadyRecorded(string $path, string $grindId): bool
    {
        foreach (AppendOnlyJsonlStore::read($path) as $row) {
            if ((string) ($row['grind_id'] ?? '') === $grindId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<int>  $sorted  ascending latencies
     */
    private function percentile(array $sorted, int $p): int
    {
        $count = count($sorted);
        if ($count === 0) {
            return 0;
        }
        $rank = (int) ceil(($p / 100) * $count);
        $rank = max(1, min($count, $rank));

        return (int) $sorted[$rank - 1];
    }

    private function now(): DateTimeImmutable
    {
        if ($this->clock !== null) {
            return ($this->clock)()->setTimezone(new DateTimeZone('UTC'));
        }

        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
