<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Telemetry;

use DateInterval;
use DateTimeImmutable;

final class AtlasLoopTelemetryStarvationDetector
{
    private const WORKER_FLOOR_RATIO = 2.0;

    /**
     * Worker-floor starvation recommendation: detects queue starvation BEFORE
     * workers actually hit no_claimable_task, by reading servable_now,
     * active_leases, claimable_per_active_worker and recent no_claimable_task
     * outcome facts directly — distinct from detect()'s claim/serve telemetry
     * pairing, which only sees starvation after it has already happened.
     *
     * recommendation:
     *   - 'worker_floor_starvation' — active workers exist AND
     *     (claimable_per_active_worker at/below the floor OR a recent
     *     no_claimable_task outcome was observed).
     *   - 'healthy_idle' — no active workers and no recent no_claimable_task
     *     outcomes; nothing to feed, nothing starving.
     *   - 'healthy' — active workers with a comfortable claimable buffer and
     *     no recent starvation outcomes.
     *
     * @param  array<string,mixed>  $facts  { servable_now?: int, active_leases?: int,
     *   claimable_per_active_worker?: float, no_claimable_task_outcome_count?: int }
     * @return array{recommendation:string, worker_floor_breached:bool, evidence:array<string,mixed>}
     */
    public function evaluateWorkerFloor(array $facts): array
    {
        $servableNow = max(0, (int) ($facts['servable_now'] ?? 0));
        $activeLeases = max(0, (int) ($facts['active_leases'] ?? 0));
        $claimablePerActiveWorker = $facts['claimable_per_active_worker'] ?? null;
        $noClaimableTaskOutcomeCount = max(0, (int) ($facts['no_claimable_task_outcome_count'] ?? 0));

        $belowFloor = $claimablePerActiveWorker !== null && (float) $claimablePerActiveWorker <= self::WORKER_FLOOR_RATIO;
        $hasRecentStarvationOutcome = $noClaimableTaskOutcomeCount > 0;

        $evidence = [
            'servable_now' => $servableNow,
            'active_leases' => $activeLeases,
            'claimable_per_active_worker' => $claimablePerActiveWorker,
            'no_claimable_task_outcome_count' => $noClaimableTaskOutcomeCount,
        ];

        if ($activeLeases > 0 && ($belowFloor || $hasRecentStarvationOutcome)) {
            return [
                'recommendation' => 'worker_floor_starvation',
                'worker_floor_breached' => true,
                'evidence' => $evidence,
            ];
        }

        if ($activeLeases === 0 && ! $hasRecentStarvationOutcome) {
            return [
                'recommendation' => 'healthy_idle',
                'worker_floor_breached' => false,
                'evidence' => $evidence,
            ];
        }

        return [
            'recommendation' => 'healthy',
            'worker_floor_breached' => false,
            'evidence' => $evidence,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $facts
     * @return array{
     *   starved:bool,
     *   window:array{from_iso:string,to_iso:string,minutes:int},
     *   counts:array{claim:int,lease:int,serve:int},
     *   evidence:array{paired_claim_to_serve:int}
     * }
     */
    public function detect(array $facts, string $nowIso, int $windowMinutes): array
    {
        $now = new DateTimeImmutable($nowIso);
        $cutoff = $now->sub(new DateInterval('PT'.max(0, $windowMinutes).'M'));
        $counts = [
            'claim' => 0,
            'lease' => 0,
            'serve' => 0,
        ];
        $byCycle = [];

        foreach ($facts as $fact) {
            if (! is_array($fact)) {
                continue;
            }

            $kind = trim((string) ($fact['kind'] ?? ''));
            $cycleId = trim((string) ($fact['cycle_id'] ?? ''));
            $occurredAtIso = trim((string) ($fact['occurred_at_iso'] ?? ''));

            if (! array_key_exists($kind, $counts) || $cycleId === '' || $occurredAtIso === '') {
                continue;
            }

            try {
                $occurredAt = new DateTimeImmutable($occurredAtIso);
            } catch (\Exception) {
                continue;
            }

            if ($occurredAt < $cutoff || $occurredAt > $now) {
                continue;
            }

            $counts[$kind]++;
            $byCycle[$cycleId][$kind] = true;
        }

        $pairedClaimToServe = 0;
        foreach ($byCycle as $events) {
            if (($events['claim'] ?? false) && ($events['serve'] ?? false)) {
                $pairedClaimToServe++;
            }
        }

        return [
            'starved' => $counts['claim'] > 0 && $counts['serve'] === 0 && $pairedClaimToServe === 0,
            'window' => [
                'from_iso' => $cutoff->format(DATE_ATOM),
                'to_iso' => $now->format(DATE_ATOM),
                'minutes' => max(0, $windowMinutes),
            ],
            'counts' => $counts,
            'evidence' => [
                'paired_claim_to_serve' => $pairedClaimToServe,
            ],
        ];
    }
}
