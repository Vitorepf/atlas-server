<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure outcome learning matrix. Groups task outcomes by family, worker and
 * model tier to surface reliability signals — no writes, no providers, no I/O.
 *
 * Input facts:
 *   outcome_rows         — list of {task_family, worker_id, model_tier,
 *                          outcome: 'success'|'failure'|'poison'}.
 *   poison_threshold     — poison_rate >= this → poison_prone (default 0.20).
 *   success_threshold    — success_rate >= this → high_success (default 0.80).
 *   worker_rely_floor    — success_rate >= this → worker reliable (default 0.70).
 *
 * AC2 — analyze() returns:
 *   family_matrix  — {task_family: {success_rate, poison_rate, failure_rate, total, signal}}.
 *   worker_matrix  — {worker_id: {success_rate, total, reliable}}.
 *   tier_matrix    — {model_tier: {success_rate, total}}.
 *   respec_families — list of poison_prone families.
 *   supply_families — list of high_success families.
 *   matrix_summary — {total_rows, families, workers, tiers}.
 *
 * AC3 — family signals (first match wins):
 *   poison_prone  — poison_rate >= poison_threshold → respec.
 *   high_success  — success_rate >= success_threshold → more supply.
 *   normal        — else.
 *
 * AC4: pure, deterministic PHP, no DB/provider/fs/queue.
 */
final class AtlasExternalBrainMuscleOutcomeLearningMatrix
{
    public const SCHEMA = 'atlas.external_brain.muscle_outcome_learning_matrix.v1';

    private const DEFAULT_POISON_THRESHOLD  = 0.20;
    private const DEFAULT_SUCCESS_THRESHOLD = 0.80;
    private const DEFAULT_WORKER_RELY_FLOOR = 0.70;

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function analyze(array $facts): array
    {
        $rows          = is_array($facts['outcome_rows']      ?? null) ? $facts['outcome_rows']      : [];
        $poisonThresh  = (float) ($facts['poison_threshold']  ?? self::DEFAULT_POISON_THRESHOLD);
        $successThresh = (float) ($facts['success_threshold'] ?? self::DEFAULT_SUCCESS_THRESHOLD);
        $workerFloor   = (float) ($facts['worker_rely_floor'] ?? self::DEFAULT_WORKER_RELY_FLOOR);

        // Accumulators: [key => [success, failure, poison, total]].
        $byFamily = [];
        $byWorker = [];
        $byTier   = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $family  = (string) ($row['task_family'] ?? '');
            $worker  = (string) ($row['worker_id']   ?? '');
            $tier    = (string) ($row['model_tier']  ?? '');
            $outcome = (string) ($row['outcome']     ?? '');

            // Tally per family.
            if (! isset($byFamily[$family])) {
                $byFamily[$family] = [0, 0, 0, 0];
            }
            $byFamily[$family][3]++;
            match ($outcome) {
                'success' => $byFamily[$family][0]++,
                'failure' => $byFamily[$family][1]++,
                'poison'  => $byFamily[$family][2]++,
                default   => null,
            };

            // Tally per worker.
            if (! isset($byWorker[$worker])) {
                $byWorker[$worker] = [0, 0];
            }
            $byWorker[$worker][1]++;
            if ($outcome === 'success') {
                $byWorker[$worker][0]++;
            }

            // Tally per tier.
            if (! isset($byTier[$tier])) {
                $byTier[$tier] = [0, 0];
            }
            $byTier[$tier][1]++;
            if ($outcome === 'success') {
                $byTier[$tier][0]++;
            }
        }

        // Build family matrix.
        $familyMatrix  = [];
        $respecFamilies = [];
        $supplyFamilies = [];

        ksort($byFamily);
        foreach ($byFamily as $family => [$succ, $fail, $poison, $total]) {
            $successRate = $total > 0 ? round($succ   / $total, 4) : 0.0;
            $poisonRate  = $total > 0 ? round($poison / $total, 4) : 0.0;
            $failureRate = $total > 0 ? round($fail   / $total, 4) : 0.0;

            // AC3: first match.
            if ($poisonRate >= $poisonThresh) {
                $signal = 'poison_prone';
                $respecFamilies[] = $family;
            } elseif ($successRate >= $successThresh) {
                $signal = 'high_success';
                $supplyFamilies[] = $family;
            } else {
                $signal = 'normal';
            }

            $familyMatrix[$family] = [
                'success_rate' => $successRate,
                'poison_rate'  => $poisonRate,
                'failure_rate' => $failureRate,
                'total'        => $total,
                'signal'       => $signal,
            ];
        }

        // Build worker matrix.
        $workerMatrix = [];
        ksort($byWorker);
        foreach ($byWorker as $worker => [$succ, $total]) {
            $successRate = $total > 0 ? round($succ / $total, 4) : 0.0;
            $workerMatrix[$worker] = [
                'success_rate' => $successRate,
                'total'        => $total,
                'reliable'     => $successRate >= $workerFloor,
            ];
        }

        // Build tier matrix.
        $tierMatrix = [];
        ksort($byTier);
        foreach ($byTier as $tier => [$succ, $total]) {
            $tierMatrix[$tier] = [
                'success_rate' => $total > 0 ? round($succ / $total, 4) : 0.0,
                'total'        => $total,
            ];
        }

        return [
            'schema_version'  => self::SCHEMA,
            'family_matrix'   => $familyMatrix,
            'worker_matrix'   => $workerMatrix,
            'tier_matrix'     => $tierMatrix,
            'respec_families' => $respecFamilies,
            'supply_families' => $supplyFamilies,
            'matrix_summary'  => [
                'total_rows' => count($rows),
                'families'   => count($familyMatrix),
                'workers'    => count($workerMatrix),
                'tiers'      => count($tierMatrix),
            ],
        ];
    }
}
