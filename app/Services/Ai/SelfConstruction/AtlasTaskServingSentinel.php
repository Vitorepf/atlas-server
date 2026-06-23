<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * PART 2 · A8 — the DETECTORS for the two mother-rule serving invariants (R1/R2). Append-only JSONL heartbeat
 * (the {@see \App\Services\Ai\Patamar4\AtlasSchedulerHealthService} pattern). These are DETECTORS, never
 * guarantees — they surface a breach loudly instead of letting serving fail silently:
 *
 *   - I-17 (R1 — the queue never dries): `recordQueueFill` logs the claimable depth; a depth below the floor
 *     raises a silent_alarm. (The queue staying full at high leverage is model-bound — see R1; the sentinel
 *     only DETECTS the dry state, honestly.)
 *   - I-18 (R2 — serving never fails): `recordServe` logs every `next` outcome; a process ERROR (as opposed to
 *     an honest `no_claimable_task`) is a breach.
 */
final class AtlasTaskServingSentinel
{
    public const SCHEMA = 'atlas.task_serving.sentinel.v1';

    /** R1 — the queue never dries below the floor. */
    public const INVARIANT_R1 = 'I-17';

    /** R2 — serving never fails to deliver (empty is honest; error is a breach). */
    public const INVARIANT_R2 = 'I-18';

    public const DEFAULT_MIN_CLAIMABLE = 1;

    /** Outcomes that are HONEST (not an R2 breach). An empty queue is honest; an error is not. */
    private const HONEST_SERVE_OUTCOMES = ['served', 'no_claimable_task', 'disabled', 'invalid_client'];

    private ?string $logPathOverride = null;

    public function setLogPathForTesting(?string $path): void
    {
        $this->logPathOverride = $path;
    }

    public function logPath(): string
    {
        return $this->logPathOverride
            ?? storage_path('app/atlas/self-construction/agent-control-plane/serving-sentinel.jsonl');
    }

    /**
     * R1/I-17 — record the claimable queue depth; a depth below the floor is a (detected) dry-queue alarm.
     *
     * @return array<string, mixed>
     */
    public function recordQueueFill(int $claimableDepth, int $minClaimable = self::DEFAULT_MIN_CLAIMABLE): array
    {
        $belowFloor = $claimableDepth < max(0, $minClaimable);

        return $this->append([
            'kind' => 'queue_fill',
            'invariant' => self::INVARIANT_R1,
            'claimable_depth' => $claimableDepth,
            'min_claimable' => $minClaimable,
            'below_floor' => $belowFloor,
            'silent_alarm' => $belowFloor,
        ]);
    }

    /**
     * R2/I-18 — record a `next` serve outcome. A non-honest outcome (e.g. 'error') is a serving breach.
     *
     * @return array<string, mixed>
     */
    public function recordServe(string $clientId, string $status): array
    {
        $breach = ! in_array($status, self::HONEST_SERVE_OUTCOMES, true);

        return $this->append([
            'kind' => 'serve',
            'invariant' => self::INVARIANT_R2,
            'client_id' => $clientId,
            'serve_status' => $status,
            'breach' => $breach,
        ]);
    }

    /**
     * Aggregate posture over the recent tail: the last queue depth + R1 alarm, the serve success rate + any
     * R2 breach. Read-only.
     *
     * @return array<string, mixed>
     */
    public function status(int $tail = 200): array
    {
        $records = $this->tail($tail);
        $serves = array_values(array_filter($records, static fn (array $r): bool => ($r['kind'] ?? '') === 'serve'));
        $fills = array_values(array_filter($records, static fn (array $r): bool => ($r['kind'] ?? '') === 'queue_fill'));

        $lastFill = $fills === [] ? null : $fills[count($fills) - 1];
        $serveTotal = count($serves);
        $served = count(array_filter($serves, static fn (array $r): bool => ($r['serve_status'] ?? '') === 'served'));
        $breaches = count(array_filter($serves, static fn (array $r): bool => ($r['breach'] ?? false) === true));

        return [
            'schema' => self::SCHEMA,
            'r1_invariant' => self::INVARIANT_R1,
            'r2_invariant' => self::INVARIANT_R2,
            'last_claimable_depth' => $lastFill === null ? null : (int) ($lastFill['claimable_depth'] ?? 0),
            'r1_silent_alarm' => $lastFill !== null && (bool) ($lastFill['below_floor'] ?? false),
            'serve_total' => $serveTotal,
            'serve_success' => $served,
            'serve_success_rate' => $serveTotal > 0 ? round($served / $serveTotal, 4) : 1.0,
            'r2_breach' => $breaches > 0,
            'r2_breach_count' => $breaches,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function append(array $payload): array
    {
        $record = array_merge(['schema' => self::SCHEMA, 'recorded_at' => $this->now()], $payload);
        $record['record_hash'] = 'sha256:'.hash('sha256', (string) json_encode($record, JSON_UNESCAPED_SLASHES));

        try {
            $path = $this->logPath();
            $dir = \dirname($path);
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            @file_put_contents($path, json_encode($record, JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // The sentinel must never break the serving path; a failed write is itself a (silent) signal.
        }

        return $record;
    }

    /** @return list<array<string, mixed>> */
    private function tail(int $tail): array
    {
        $path = $this->logPath();
        if (! is_file($path)) {
            return [];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lines = array_slice($lines, -max(1, $tail));
        $out = [];
        foreach ($lines as $line) {
            $decoded = json_decode((string) $line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    private function now(): string
    {
        return CarbonImmutable::now()->toIso8601String();
    }
}
