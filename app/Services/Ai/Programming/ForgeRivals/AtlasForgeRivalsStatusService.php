<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Atlas Forge Rivals · Status.
 *
 * Tails events.jsonl for a given run, reports the current phase, computes
 * heartbeat_age_seconds, and flips to `stalled_runner_no_heartbeat` when
 * the last event is older than the stall threshold.
 *
 * Read-only. Never invokes provider.
 */
final class AtlasForgeRivalsStatusService
{
    public const HEARTBEAT_STALL_SECONDS = 30;

    public function __construct(
        private readonly AtlasForgeRivalsRunPathResolver $paths,
        private readonly AtlasForgeRivalsEventStream $events,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function status(array $input): array
    {
        $runId = trim((string) ($input['run_id'] ?? ''));
        if ($runId === '') {
            return [
                'status' => 'blocked',
                'blockers' => ['run_id_required'],
                'next_command' => 'php artisan atlas:forge:rivals status --run-id=<id> --json',
            ];
        }

        $paths = $this->paths->paths($runId);
        if (! is_file($paths['events_jsonl'])) {
            return [
                'status' => 'blocked',
                'blockers' => ['run_not_found:'.$paths['run_id']],
                'run_id' => $paths['run_id'],
                'next_command' => 'php artisan atlas:forge:rivals run-real --mode=local_fake --preset=smoke --json',
            ];
        }

        $events = $this->events->tail($runId, 200);
        $lastEvent = $events !== [] ? end($events) : null;
        $lastKind = is_array($lastEvent) ? (string) ($lastEvent['kind'] ?? '') : '';
        $lastTs = is_array($lastEvent) ? (string) ($lastEvent['ts'] ?? '') : '';

        $heartbeatAge = null;
        $isStalled = false;
        if ($lastTs !== '') {
            try {
                $lastDt = new DateTimeImmutable($lastTs);
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $heartbeatAge = max(0, $now->getTimestamp() - $lastDt->getTimestamp());
                $isStalled = ! in_array($lastKind, ['final_report', 'evidence_pack'], true)
                    && $heartbeatAge > self::HEARTBEAT_STALL_SECONDS;
            } catch (\Throwable) {
                // ignore
            }
        }

        $finalReport = null;
        foreach (array_reverse($events) as $e) {
            if (is_array($e) && ($e['kind'] ?? null) === 'final_report') {
                $finalReport = $e['payload'] ?? null;
                break;
            }
        }

        $status = $isStalled ? 'stalled_runner_no_heartbeat' : ($finalReport ? 'completed' : 'running');

        return [
            'status' => $status,
            'run_id' => $paths['run_id'],
            'phase' => $lastKind ?: 'unknown',
            'last_event_at' => $lastTs ?: null,
            'heartbeat_age_seconds' => $heartbeatAge,
            'heartbeat_stall_threshold_seconds' => self::HEARTBEAT_STALL_SECONDS,
            'is_stalled' => $isStalled,
            'event_count' => count($events),
            'final_report' => $finalReport,
            'paths' => $paths,
            'external_provider_call' => false,
            'next_command' => $finalReport
                ? 'php artisan atlas:forge:rivals collect-evidence --run-id='.$paths['run_id'].' --json'
                : 'php artisan atlas:forge:rivals status --run-id='.$paths['run_id'].' --json',
        ];
    }
}
