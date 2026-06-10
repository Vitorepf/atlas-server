<?php

declare(strict_types=1);

namespace App\Services\Ai\Patamar4;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use App\Services\Ai\Support\AppendOnlyJsonlStore;

/**
 * Atlas Scheduler Health Service — Patamar 4 Cron OS 24/7 probe.
 *
 * Append-only JSONL heartbeat ledger. Cada tick do scheduler Laravel
 * grava um heartbeat com timestamp UTC + sha256. Operador inspeciona
 * status via `atlas:scheduler:status` ou /atlas/patamar4/state.scheduler.
 *
 * Silent alarm: se age_seconds > threshold (default 300s) o probe marca
 * `silent_alarm=true` — sinal que o launchd parou de disparar.
 *
 * Authority doc: docs/engineering-knowledge-base/atlas-scheduler-os.md
 *
 * Schemas:
 *   - atlas.scheduler.heartbeat.v1
 *   - atlas.scheduler.status.v1
 *
 * Invariants:
 *   - Append-only JSONL, sha256 per record.
 *   - claim_policy provider-safe.
 *   - Local-first (arquivo em storage/atlas/scheduler/).
 *   - Read-only para Patamar4 state.
 */
final class AtlasSchedulerHealthService
{
    public const HEARTBEAT_SCHEMA = 'atlas.scheduler.heartbeat.v1';

    public const STATUS_SCHEMA = 'atlas.scheduler.status.v1';

    public const DEFAULT_SILENT_THRESHOLD_SECONDS = 300;

    private ?string $logPathOverride = null;

    public function setLogPathForTesting(?string $path): void
    {
        $this->logPathOverride = $path;
    }

    public function logPath(): string
    {
        if ($this->logPathOverride !== null) {
            return $this->logPathOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/scheduler')
            : sys_get_temp_dir().'/atlas/scheduler';

        return $base.DIRECTORY_SEPARATOR.'heartbeat.jsonl';
    }

    /**
     * Record a heartbeat tick.
     *
     * @return array<string,mixed>
     */
    public function recordHeartbeat(string $actor = 'scheduler_tick'): array
    {
        $ts = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
        $payload = [
            'schema_version' => self::HEARTBEAT_SCHEMA,
            'timestamp' => $ts,
            'actor' => $actor,
            'pid' => function_exists('getmypid') ? getmypid() : null,
        ];
        $payload['heartbeat_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::HEARTBEAT_SCHEMA,
            'timestamp' => $ts,
            'actor' => $actor,
        ], JSON_THROW_ON_ERROR));

        AppendOnlyJsonlStore::append($this->logPath(), $payload);

        return $payload;
    }

    /**
     * Read tail of heartbeats.
     *
     * @return list<array<string,mixed>>
     */
    public function listHeartbeats(int $tail = 50): array
    {
        $all = AppendOnlyJsonlStore::read($this->logPath());
        if ($tail <= 0) {
            return $all;
        }

        return array_slice($all, -$tail);
    }

    public function lastHeartbeat(): ?array
    {
        $all = AppendOnlyJsonlStore::read($this->logPath());

        return $all === [] ? null : $all[count($all) - 1];
    }

    /**
     * Status snapshot — used by CLI and Patamar 4 state.
     *
     * @return array<string,mixed>
     */
    public function status(?int $silentThresholdSeconds = null): array
    {
        $threshold = $silentThresholdSeconds ?? self::DEFAULT_SILENT_THRESHOLD_SECONDS;
        $last = $this->lastHeartbeat();
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        if ($last === null) {
            return [
                'schema_version' => self::STATUS_SCHEMA,
                'generated_at' => $now->format(DateTimeInterface::ATOM),
                'last_heartbeat_at' => null,
                'age_seconds' => null,
                'silent_alarm' => true,
                'silent_threshold_seconds' => $threshold,
                'heartbeat_count' => 0,
                'reason' => 'no_heartbeat_recorded',
                'claim_policy' => $this->claimPolicy(),
            ];
        }

        try {
            $lastAt = new DateTimeImmutable((string) $last['timestamp']);
            $age = $now->getTimestamp() - $lastAt->getTimestamp();
        } catch (\Throwable) {
            $age = null;
        }
        $silent = $age === null || $age > $threshold;

        return [
            'schema_version' => self::STATUS_SCHEMA,
            'generated_at' => $now->format(DateTimeInterface::ATOM),
            'last_heartbeat_at' => $last['timestamp'] ?? null,
            'age_seconds' => $age,
            'silent_alarm' => $silent,
            'silent_threshold_seconds' => $threshold,
            'heartbeat_count' => count(AppendOnlyJsonlStore::read($this->logPath())),
            'reason' => $silent ? 'age_exceeds_threshold' : 'within_threshold',
            'claim_policy' => $this->claimPolicy(),
        ];
    }

    /**
     * @return array<string,bool>
     */
    public function claimPolicy(): array
    {
        return [
            'benchmark_claim_allowed' => false,
            'rivals_claim_allowed' => false,
            'superiority_claim_allowed' => false,
            'external_rivals_certification_touched' => false,
            'cognitive_immune_law_enforced' => true,
            'provider_safe_only_enforced' => true,
            'local_first_only' => true,
        ];
    }

    // ---------- internals ----------
}
