<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Rich outcome learning matrix. Groups task outcomes by family, worker and
 * model tier to surface reliability signals — no writes, no providers, no I/O.
 *
 * Outcome types: success, give_back, failure, poison, quarantine, duplicate, weak_green.
 * Unknown outcomes are silently ignored in signal computation but count toward total.
 *
 * Input facts:
 *   outcome_rows              — list of {task_family, worker_id, model_tier, outcome}.
 *   poison_threshold          — poison_rate >= this → poison_prone (default 0.20).
 *   quarantine_threshold      — quarantine_rate >= this → quarantine_prone (default 0.30).
 *   duplicate_threshold       — duplicate_rate >= this → duplicate_prone (default 0.30).
 *   success_threshold         — success_rate >= this → high_success (default 0.80).
 *   worker_rely_floor         — success_rate >= this → reliable (default 0.70).
 *   min_give_back_flag        — give_back_count >= this → worker_reliability_signal (default 2).
 *   repeat_offender_floor     — worker success_rate < this → repeat offender candidate (default 0.30).
 *   repeat_offender_min_rows  — worker total >= this for repeat-offender check (default 3).
 *
 * AC2 — analyze() returns:
 *   family_matrix  — per family: success/give_back/failure/poison/quarantine/duplicate/weak_green rates,
 *                    total, signal.
 *   worker_matrix  — per worker: success_rate, give_back_count, total, reliable.
 *   tier_matrix    — per tier: success_rate, total.
 *   respec_families         — poison_prone | quarantine_prone | duplicate_prone families.
 *   supply_families         — high_success families.
 *   worker_reliability_signals — workers with repeat give_back (give_back_count >= min_give_back_flag).
 *   repeat_offenders        — workers with consistently low success_rate (< floor, total >= min).
 *   matrix_summary          — {total_rows, families, workers, tiers}.
 *
 * AC3 — family signal priority (first match):
 *   poison_prone      — poison_rate >= poison_threshold    → respec_families.
 *   quarantine_prone  — quarantine_rate >= quar_threshold  → respec_families.
 *   duplicate_prone   — duplicate_rate >= dup_threshold    → respec_families.
 *   high_success      — success_rate >= success_threshold  → supply_families.
 *   normal            — else.
 *
 * AC4 — pure, deterministic PHP, no DB/provider/fs/queue.
 */
final class AtlasExternalBrainMuscleOutcomeLearningMatrix
{
    public const SCHEMA = 'atlas.external_brain.muscle_outcome_learning_matrix.v1';

    private const DEFAULT_POISON_THRESHOLD          = 0.20;
    private const DEFAULT_QUARANTINE_THRESHOLD      = 0.30;
    private const DEFAULT_DUPLICATE_THRESHOLD       = 0.30;
    private const DEFAULT_SUCCESS_THRESHOLD         = 0.80;
    private const DEFAULT_WORKER_RELY_FLOOR         = 0.70;
    private const DEFAULT_MIN_GIVE_BACK_FLAG        = 2;
    private const DEFAULT_REPEAT_OFFENDER_FLOOR     = 0.30;
    private const DEFAULT_REPEAT_OFFENDER_MIN_ROWS  = 3;

    /** Routing recommendation thresholds (family×worker/tier cross matrix). */
    private const DEFAULT_ROUTING_PREFER_FLOOR  = 0.70; // success_rate >= this → prefer
    private const DEFAULT_ROUTING_AVOID_CEILING = 0.40; // success_rate <  this → avoid
    private const DEFAULT_ROUTING_MIN_ROWS      = 2;    // minimum cross rows for a recommendation

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function analyze(array $facts): array
    {
        $rows              = is_array($facts['outcome_rows']              ?? null) ? $facts['outcome_rows']              : [];
        $poisonThresh      = (float) ($facts['poison_threshold']          ?? self::DEFAULT_POISON_THRESHOLD);
        $quarThresh        = (float) ($facts['quarantine_threshold']      ?? self::DEFAULT_QUARANTINE_THRESHOLD);
        $dupThresh         = (float) ($facts['duplicate_threshold']       ?? self::DEFAULT_DUPLICATE_THRESHOLD);
        $successThresh     = (float) ($facts['success_threshold']         ?? self::DEFAULT_SUCCESS_THRESHOLD);
        $workerFloor       = (float) ($facts['worker_rely_floor']         ?? self::DEFAULT_WORKER_RELY_FLOOR);
        $minGiveBackFlag   = (int)   ($facts['min_give_back_flag']        ?? self::DEFAULT_MIN_GIVE_BACK_FLAG);
        $repeatFloor       = (float) ($facts['repeat_offender_floor']     ?? self::DEFAULT_REPEAT_OFFENDER_FLOOR);
        $repeatMinRows     = (int)   ($facts['repeat_offender_min_rows']  ?? self::DEFAULT_REPEAT_OFFENDER_MIN_ROWS);
        $routingPrefer     = (float) ($facts['routing_prefer_floor']      ?? self::DEFAULT_ROUTING_PREFER_FLOOR);
        $routingAvoid      = (float) ($facts['routing_avoid_ceiling']     ?? self::DEFAULT_ROUTING_AVOID_CEILING);
        $routingMinRows    = (int)   ($facts['routing_min_rows']          ?? self::DEFAULT_ROUTING_MIN_ROWS);

        // Accumulators — family: [success, give_back, failure, poison, quarantine, duplicate, weak_green, total]
        $byFamily = [];
        // Worker: [success, give_back, total]
        $byWorker = [];
        // Tier: [success, total]
        $byTier   = [];
        // Cross: family×worker [success, give_back, poison, quarantine, total]
        $byFamilyWorker = [];
        // Cross: family×tier [success, total]
        $byFamilyTier   = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $family  = (string) ($row['task_family'] ?? '');
            $worker  = (string) ($row['worker_id']   ?? '');
            $tier    = (string) ($row['model_tier']  ?? '');
            $outcome = (string) ($row['outcome']     ?? '');

            // Init accumulators.
            if (! isset($byFamily[$family])) {
                $byFamily[$family] = [0, 0, 0, 0, 0, 0, 0, 0]; // s,gb,fail,poison,quar,dup,wg,total
            }
            if (! isset($byWorker[$worker])) {
                $byWorker[$worker] = [0, 0, 0]; // success, give_back, total
            }
            if (! isset($byTier[$tier])) {
                $byTier[$tier] = [0, 0]; // success, total
            }
            if (! isset($byFamilyWorker[$family][$worker])) {
                $byFamilyWorker[$family][$worker] = [0, 0, 0, 0, 0]; // s, gb, poison, quar, total
            }
            if (! isset($byFamilyTier[$family][$tier])) {
                $byFamilyTier[$family][$tier] = [0, 0]; // success, total
            }

            $byFamily[$family][7]++;
            $byWorker[$worker][2]++;
            $byTier[$tier][1]++;
            $byFamilyWorker[$family][$worker][4]++;
            $byFamilyTier[$family][$tier][1]++;

            if ($outcome === 'success') {
                $byFamily[$family][0]++;
                $byWorker[$worker][0]++;
                $byTier[$tier][0]++;
                $byFamilyWorker[$family][$worker][0]++;
                $byFamilyTier[$family][$tier][0]++;
            } elseif ($outcome === 'give_back') {
                $byFamily[$family][1]++;
                $byWorker[$worker][1]++;
                $byFamilyWorker[$family][$worker][1]++;
            } elseif ($outcome === 'failure') {
                $byFamily[$family][2]++;
            } elseif ($outcome === 'poison') {
                $byFamily[$family][3]++;
                $byFamilyWorker[$family][$worker][2]++;
            } elseif ($outcome === 'quarantine') {
                $byFamily[$family][4]++;
                $byFamilyWorker[$family][$worker][3]++;
            } elseif ($outcome === 'duplicate') {
                $byFamily[$family][5]++;
            } elseif ($outcome === 'weak_green') {
                $byFamily[$family][6]++;
            }
        }

        // Build family matrix.
        $familyMatrix   = [];
        $respecFamilies = [];
        $supplyFamilies = [];

        ksort($byFamily);
        foreach ($byFamily as $family => [$succ, $gb, $fail, $poison, $quar, $dup, $wg, $total]) {
            $rate = fn(int $n) => $total > 0 ? round($n / $total, 4) : 0.0;

            $successRate    = $rate($succ);
            $giveBackRate   = $rate($gb);
            $failureRate    = $rate($fail);
            $poisonRate     = $rate($poison);
            $quarantineRate = $rate($quar);
            $duplicateRate  = $rate($dup);
            $weakGreenRate  = $rate($wg);

            if ($poisonRate >= $poisonThresh) {
                $signal = 'poison_prone';
                $respecFamilies[] = $family;
            } elseif ($quarantineRate >= $quarThresh) {
                $signal = 'quarantine_prone';
                $respecFamilies[] = $family;
            } elseif ($duplicateRate >= $dupThresh) {
                $signal = 'duplicate_prone';
                $respecFamilies[] = $family;
            } elseif ($successRate >= $successThresh) {
                $signal = 'high_success';
                $supplyFamilies[] = $family;
            } else {
                $signal = 'normal';
            }

            $familyMatrix[$family] = [
                'success_rate'    => $successRate,
                'give_back_rate'  => $giveBackRate,
                'failure_rate'    => $failureRate,
                'poison_rate'     => $poisonRate,
                'quarantine_rate' => $quarantineRate,
                'duplicate_rate'  => $duplicateRate,
                'weak_green_rate' => $weakGreenRate,
                'total'           => $total,
                'signal'          => $signal,
            ];
        }

        // Build worker matrix + reliability signals + repeat offenders.
        $workerMatrix            = [];
        $workerReliabilitySignals = [];
        $repeatOffenders         = [];

        ksort($byWorker);
        foreach ($byWorker as $worker => [$succ, $gb, $total]) {
            $successRate = $total > 0 ? round($succ / $total, 4) : 0.0;
            $reliable    = $successRate >= $workerFloor;

            $workerMatrix[$worker] = [
                'success_rate'   => $successRate,
                'give_back_count' => $gb,
                'total'          => $total,
                'reliable'       => $reliable,
            ];

            if ($gb >= $minGiveBackFlag) {
                $workerReliabilitySignals[] = [
                    'worker_id'      => $worker,
                    'give_back_count' => $gb,
                    'give_back_rate'  => $total > 0 ? round($gb / $total, 4) : 0.0,
                    'signal'         => 'repeat_give_back',
                ];
            }

            if ($total >= $repeatMinRows && $successRate < $repeatFloor) {
                $repeatOffenders[] = [
                    'worker_id'    => $worker,
                    'success_rate' => $successRate,
                    'total'        => $total,
                    'offender_type' => 'low_success_worker',
                ];
            }
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

        // Build family×worker fit cross matrix.
        $familyWorkerFit = [];
        ksort($byFamilyWorker);
        foreach ($byFamilyWorker as $fam => $workers) {
            ksort($workers);
            $familyWorkerFit[$fam] = [];
            foreach ($workers as $wk => [$s, $gb, $poison, $quar, $total]) {
                $r = static fn (int $n, int $t): float => $t > 0 ? round($n / $t, 4) : 0.0;
                $familyWorkerFit[$fam][$wk] = [
                    'success_rate'    => $r($s,      $total),
                    'give_back_count' => $gb,
                    'poison_rate'     => $r($poison,  $total),
                    'quarantine_rate' => $r($quar,    $total),
                    'total'           => $total,
                ];
            }
        }

        // Build family×tier fit cross matrix.
        $familyTierFit = [];
        ksort($byFamilyTier);
        foreach ($byFamilyTier as $fam => $tiers) {
            ksort($tiers);
            $familyTierFit[$fam] = [];
            foreach ($tiers as $tier => [$s, $total]) {
                $familyTierFit[$fam][$tier] = [
                    'success_rate' => $total > 0 ? round($s / $total, 4) : 0.0,
                    'total'        => $total,
                ];
            }
        }

        // Build routing recommendations from cross matrices.
        $routingRecommendations = [];
        foreach ($familyWorkerFit as $fam => $workers) {
            $preferred  = [];
            $avoid      = [];
            $failClosed = [];

            foreach ($workers as $wk => $fit) {
                if ($fit['total'] < $routingMinRows) {
                    continue;
                }
                if ($fit['poison_rate'] >= $poisonThresh || $fit['quarantine_rate'] >= $quarThresh) {
                    $failClosed[] = [
                        'worker_id'       => $wk,
                        'poison_rate'     => $fit['poison_rate'],
                        'quarantine_rate' => $fit['quarantine_rate'],
                        'reason'          => 'poison_prone_combination',
                        'routing'         => 'fail_closed',
                    ];
                } elseif ($fit['success_rate'] >= $routingPrefer) {
                    $preferred[] = [
                        'worker_id'    => $wk,
                        'success_rate' => $fit['success_rate'],
                        'reason'       => 'high_family_success_rate',
                        'routing'      => 'prefer',
                    ];
                } elseif ($fit['success_rate'] < $routingAvoid) {
                    $avoid[] = [
                        'worker_id'    => $wk,
                        'success_rate' => $fit['success_rate'],
                        'reason'       => 'low_family_success_rate',
                        'routing'      => 'avoid',
                    ];
                }
            }

            // Preferred tier = highest success_rate among cross rows meeting min_rows.
            $preferredTier = null;
            $bestRate      = -1.0;
            foreach ($familyTierFit[$fam] ?? [] as $tier => $fit) {
                if ($fit['total'] >= $routingMinRows && $fit['success_rate'] > $bestRate) {
                    $bestRate      = $fit['success_rate'];
                    $preferredTier = $tier;
                }
            }

            usort($preferred, static fn ($a, $b) => $b['success_rate'] <=> $a['success_rate']);

            $routingRecommendations[$fam] = [
                'preferred_workers'        => $preferred,
                'avoid_workers'            => $avoid,
                'fail_closed_combinations' => $failClosed,
                'preferred_tier'           => $preferredTier,
                'routing_basis'            => 'family_worker_success_rate',
            ];
        }

        return [
            'schema_version'             => self::SCHEMA,
            'family_matrix'              => $familyMatrix,
            'worker_matrix'              => $workerMatrix,
            'tier_matrix'                => $tierMatrix,
            'family_worker_fit'          => $familyWorkerFit,
            'family_tier_fit'            => $familyTierFit,
            'routing_recommendations'    => $routingRecommendations,
            'respec_families'            => $respecFamilies,
            'supply_families'            => $supplyFamilies,
            'worker_reliability_signals' => $workerReliabilitySignals,
            'repeat_offenders'           => $repeatOffenders,
            'matrix_summary'             => [
                'total_rows' => count($rows),
                'families'   => count($familyMatrix),
                'workers'    => count($workerMatrix),
                'tiers'      => count($tierMatrix),
            ],
        ];
    }
}
