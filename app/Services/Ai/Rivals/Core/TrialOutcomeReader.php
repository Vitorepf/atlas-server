<?php

namespace App\Services\Ai\Rivals\Core;

use App\Services\Ai\EngineeringKernel\EngineeringOutcome;

/**
 * Read canonical 0h–150d outcomes for a trial by reference (release/run hash) and roll
 * them into the outcome bundle the AdversarialAdjudicationGate consumes. Outcomes stay
 * owned by the canonical Atlas store — this reader copies nothing into a Rivals ledger.
 * An unobserved or non-elapsed window keeps the claim uncertain; a contradiction across
 * windows is surfaced, never smoothed into a green rate. Pure over arrays.
 */
final class TrialOutcomeReader
{
    public const SCHEMA = 'atlas.rivals2.trial_outcome_reader.v1';

    /** @var list<string> */
    private const ADVERSE = ['adverse', 'failed', 'failure', 'regressed', 'rollback', 'incident'];

    /**
     * @param  list<array<string,mixed>>  $observations  canonical OutcomeObservation arrays (referenced)
     * @param  string  $requiredWindow  highest window that must be observed for the claim horizon
     * @return array{observed: bool, elapsed: bool, contradictory: bool, windows: array<string,array{observed:bool,adverse:bool}>}
     */
    public function read(array $observations, string $requiredWindow = '30d'): array
    {
        $byWindow = [];
        foreach ($observations as $observation) {
            $window = (string) ($observation['window'] ?? '');
            if (! in_array($window, EngineeringOutcome::WINDOWS, true)) {
                continue;
            }
            $status = strtolower(trim((string) ($observation['metrics']['status'] ?? $observation['status'] ?? '')));
            if ($status !== '') {
                $byWindow[$window][] = $status;
            }
        }

        $healthy = false;
        $adverse = false;
        $windows = [];
        foreach (EngineeringOutcome::WINDOWS as $window) {
            $statuses = $byWindow[$window] ?? [];
            $windowAdverse = array_intersect($statuses, self::ADVERSE) !== [];
            $windowHealthy = $statuses !== [] && ! $windowAdverse;
            if ($windowAdverse) {
                $adverse = true;
            }
            if ($windowHealthy) {
                $healthy = true;
            }
            $windows[$window] = ['observed' => $statuses !== [], 'adverse' => $windowAdverse];
        }

        $observed = $byWindow !== [];
        $horizon = array_search($requiredWindow, EngineeringOutcome::WINDOWS, true);
        $required = $horizon === false
            ? EngineeringOutcome::WINDOWS
            : array_slice(EngineeringOutcome::WINDOWS, 0, $horizon + 1);
        $elapsed = $observed;
        foreach ($required as $window) {
            if (($windows[$window]['observed'] ?? false) !== true) {
                $elapsed = false;
                break;
            }
        }

        return [
            'observed' => $observed,
            'elapsed' => $elapsed,
            'contradictory' => $healthy && $adverse,
            'windows' => $windows,
        ];
    }
}
