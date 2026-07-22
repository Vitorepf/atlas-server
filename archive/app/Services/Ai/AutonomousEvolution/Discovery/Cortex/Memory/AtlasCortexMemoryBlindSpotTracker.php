<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Memory;

/**
 * Reads the episodic ledger and emits the FACT of which blind_spots (gap_id, kind) persisted unsolved
 * across N or more consecutive cycles.
 *
 * RESOLUTION: a gap is RESOLVED the first cycle it does NOT appear with the same (kind, gap_id) tuple.
 * REAPPEARANCE after a resolution starts a FRESH run — runs are NOT merged.
 *
 * INVARIANTS:
 *   - One row per distinct RUN (multiple rows possible per gap_id when it disappears and returns later).
 *   - NO opinion on severity, NO scoring, NO ranking-by-importance.
 *   - Output sort: (persistence_runs desc, last_seen_cycle_id desc, gap_id asc).
 *   - Deterministic: identical ledger ⇒ byte-identical output (no time-of-call dependency).
 */
final class AtlasCortexMemoryBlindSpotTracker
{
    public function __construct(private readonly AtlasCortexMemoryEpisodicLedger $ledger) {}

    /**
     * @return list<array{gap_id:string, kind:string, persistence_runs:int, first_seen_cycle_id:string, last_seen_cycle_id:string, first_seen_unix:int, last_seen_unix:int}>
     */
    public function persistentBlindSpots(int $minPersistence, ?int $sinceUnix = null): array
    {
        $episodes = iterator_to_array($this->ledger->iterate(limit: null, sinceUnix: $sinceUnix), false);

        // Track ACTIVE runs per identity-key, and COMPLETED runs (commit on resolution OR end-of-stream).
        // Identity-key = gap_id + "\0" + kind.
        $active = [];     // key => {gap_id, kind, persistence_runs, first_seen_cycle_id, last_seen_cycle_id, first_seen_unix, last_seen_unix}
        $completed = [];  // list of completed run records

        foreach ($episodes as $episode) {
            $cycleId = (string) ($episode['cycle_id'] ?? '');
            $capturedAt = (int) ($episode['captured_at'] ?? 0);
            $seenThisCycle = [];

            foreach ((array) ($episode['blind_spots'] ?? []) as $gap) {
                if (! is_array($gap)) {
                    continue;
                }
                $gapId = (string) ($gap['gap_id'] ?? '');
                $kind = (string) ($gap['kind'] ?? '');
                if ($gapId === '') {
                    continue;
                }
                $key = $gapId."\0".$kind;
                $seenThisCycle[$key] = true;

                if (isset($active[$key])) {
                    $active[$key]['persistence_runs']++;
                    $active[$key]['last_seen_cycle_id'] = $cycleId;
                    $active[$key]['last_seen_unix'] = $capturedAt;
                } else {
                    $active[$key] = [
                        'gap_id' => $gapId,
                        'kind' => $kind,
                        'persistence_runs' => 1,
                        'first_seen_cycle_id' => $cycleId,
                        'last_seen_cycle_id' => $cycleId,
                        'first_seen_unix' => $capturedAt,
                        'last_seen_unix' => $capturedAt,
                    ];
                }
            }

            // Any active gap not seen this cycle ⇒ run is RESOLVED. Commit to completed, drop from active.
            foreach (array_keys($active) as $key) {
                if (! isset($seenThisCycle[$key])) {
                    $completed[] = $active[$key];
                    unset($active[$key]);
                }
            }
        }

        // End-of-stream: any still-active runs are also recorded (they are FACT — currently-running runs).
        foreach ($active as $run) {
            $completed[] = $run;
        }

        $out = [];
        foreach ($completed as $run) {
            if ($run['persistence_runs'] >= $minPersistence) {
                $out[] = $run;
            }
        }
        usort($out, static function (array $a, array $b): int {
            return $b['persistence_runs'] <=> $a['persistence_runs']
                ?: strcmp($b['last_seen_cycle_id'], $a['last_seen_cycle_id'])
                ?: strcmp($a['gap_id'], $b['gap_id']);
        });

        return $out;
    }
}
