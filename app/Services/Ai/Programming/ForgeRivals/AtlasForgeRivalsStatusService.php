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
        private readonly AtlasForgeRivalsBatteryStateService $battery,
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
        $batterySnapshot = $this->battery->snapshot($paths['run_id']);
        if (! is_file($paths['events_jsonl'])) {
            if (($batterySnapshot['exists'] ?? false) === true) {
                // No events.jsonl yet but a battery catalogue exists: return
                // the catalogue so `status` can surface partial / paused
                // batteries without pretending the run is missing.
                return [
                    'status' => 'ok',
                    'run_id' => $paths['run_id'],
                    'phase' => 'battery_initialised_no_events_yet',
                    'is_stalled' => false,
                    'progress' => $this->progressCounters($batterySnapshot),
                    'battery' => $batterySnapshot,
                    'paths' => $paths,
                    'external_provider_call' => false,
                    'next_command' => 'php artisan atlas:forge:rivals resume --run-id='.$paths['run_id'].' --json',
                ];
            }

            return [
                'status' => 'blocked',
                'blockers' => ['run_not_found:'.$paths['run_id']],
                'run_id' => $paths['run_id'],
                'progress' => $this->progressCounters(null),
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

        $nextCommand = $finalReport
            ? 'php artisan atlas:forge:rivals collect-evidence --run-id='.$paths['run_id'].' --json'
            : 'php artisan atlas:forge:rivals status --run-id='.$paths['run_id'].' --json';

        // When battery.json exists with cases still pending, the canonical
        // next move is `resume`, not just refresh status.
        if (($batterySnapshot['exists'] ?? false) === true) {
            $pendingCount = (int) ($batterySnapshot['pending_case_count'] ?? 0);
            if ($pendingCount > 0) {
                $nextCommand = 'php artisan atlas:forge:rivals resume --run-id='.$paths['run_id'].' --json';
            }
        }

        $progress = $this->progressCounters($batterySnapshot);

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
            'progress' => $progress,
            'battery' => $batterySnapshot,
            'paths' => $paths,
            'external_provider_call' => false,
            'next_command' => $nextCommand,
        ];
    }

    /**
     * Canonical progress block: total / passed / failed / invalid /
     * running / pending / skipped / remaining. `remaining` = pending +
     * running (cases that still need to settle); kept separate from
     * pending so dashboards can render a "still to run" counter without
     * subtracting attempts on stuck-in-running cases.
     *
     * Always returns the eight keys, even when no battery exists, so
     * callers can dereference without conditionals.
     *
     * @param  array<string,mixed>|null  $batterySnapshot
     * @return array<string,int>
     */
    private function progressCounters(?array $batterySnapshot): array
    {
        $counters = [
            'total' => 0,
            'passed' => 0,
            'failed' => 0,
            'invalid' => 0,
            'running' => 0,
            'pending' => 0,
            'skipped' => 0,
            'remaining' => 0,
        ];
        if (! is_array($batterySnapshot) || ! ($batterySnapshot['exists'] ?? false)) {
            return $counters;
        }
        $counts = is_array($batterySnapshot['state_counts'] ?? null) ? $batterySnapshot['state_counts'] : [];
        $counters['total'] = (int) ($batterySnapshot['case_count'] ?? 0);
        $counters['passed'] = (int) ($counts[AtlasForgeRivalsBatteryStateService::CASE_STATE_COMPLETED] ?? 0);
        $counters['failed'] = (int) ($counts[AtlasForgeRivalsBatteryStateService::CASE_STATE_FAILED] ?? 0);
        $counters['invalid'] = (int) ($counts[AtlasForgeRivalsBatteryStateService::CASE_STATE_INVALID] ?? 0);
        $counters['running'] = (int) ($counts[AtlasForgeRivalsBatteryStateService::CASE_STATE_RUNNING] ?? 0);
        $counters['pending'] = (int) ($counts[AtlasForgeRivalsBatteryStateService::CASE_STATE_PENDING] ?? 0);
        $counters['skipped'] = (int) ($counts[AtlasForgeRivalsBatteryStateService::CASE_STATE_SKIPPED] ?? 0);
        $counters['remaining'] = $counters['pending'] + $counters['running'];

        return $counters;
    }
}
