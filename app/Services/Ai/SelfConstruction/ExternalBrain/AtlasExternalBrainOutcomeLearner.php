<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Closes the learning loop between the task muscle and the brain: adjusts future pattern-family
 * priority from real delivery outcomes and emits bounded, provider-safe next-wave search hints.
 *
 * INPUT outcomes:
 *   list<{ task_packet_id:string, pattern_family:string, outcome:string, impact?:string,
 *           give_back_count?:int, task_family?:string, worker_id?:string, client_id?:string }>
 *
 * OUTCOME VALUES:
 *   delivered — task completed and committed; positive signal.
 *   give_back — worker returned the task; negative signal weighted by give_back_count.
 *   proxy     — task shipped but was proxy/filler (no real capability gain); stronger negative.
 *
 * IMPACT (for delivered outcomes): high | medium | low — scales the positive delta.
 *
 * OUTPUT:
 *   { schema, priority_adjustments:list<Adjustment>, next_wave_hints:list<Hint>,
 *     promoted:list<string>, demoted:list<string>,
 *     family_performance:list<FamilyPerf>, give_back_risk:list<GiveBackRisk>,
 *     poison_family_hints:list<PoisonHint>, worker_fit_hints:list<WorkerFit>,
 *     next_wave_adjustments:list<WaveAdj> }
 *
 * Adjustment:    { pattern_family, delta:float[-1.0..+1.0], reason:string }
 * Hint:          { source_category:string, focus:string, priority:float[0..1] }
 * FamilyPerf:    { task_family, total, delivered, give_back, proxy, success_rate }
 * GiveBackRisk:  { task_family, risk_level:'low'|'medium'|'high', give_back_rate }
 * PoisonHint:    { task_family, reason, suggested_action }
 * WorkerFit:     { worker_id, task_family, fit:'strong'|'weak'|'neutral', success_rate }
 * WaveAdj:       { task_family, priority_delta:float, reason:string }
 *
 * INVARIANTS:
 *   - No hidden model prompts, no external calls, no unverifiable claims. Pure output.
 *   - Delta is always clamped to [-1.0, +1.0].
 *   - promoted/demoted lists are disjoint.
 *   - Poison hints for one task_family never suppress unrelated high-performing families.
 * PURE / DETERMINISTIC. No I/O.
 */
final class AtlasExternalBrainOutcomeLearner
{
    public const SCHEMA = 'atlas.external_brain.outcome_learner.v1';

    public const OUTCOME_DELIVERED = 'delivered';

    public const OUTCOME_GIVE_BACK = 'give_back';

    public const OUTCOME_PROXY = 'proxy';

    public const IMPACT_HIGH   = 'high';

    public const IMPACT_MEDIUM = 'medium';

    public const IMPACT_LOW    = 'low';

    /** Base deltas before per-outcome scaling. */
    private const DELTA_DELIVERED_HIGH   = +0.30;

    private const DELTA_DELIVERED_MEDIUM = +0.15;

    private const DELTA_DELIVERED_LOW    = +0.05;

    private const DELTA_GIVE_BACK_BASE   = -0.20;

    private const DELTA_PROXY            = -0.40;

    /** Minimum absolute delta before a pattern is considered promoted/demoted. */
    private const PROMOTE_THRESHOLD = 0.01;

    /** Poison threshold: give_back_rate ≥ this OR give_back_count ≥ 3 */
    private const POISON_RATE_THRESHOLD = 0.60;

    private const POISON_COUNT_THRESHOLD = 3;

    /**
     * @param  list<array{task_packet_id:string, pattern_family:string, outcome:string, impact?:string,
     *                    give_back_count?:int, task_family?:string, worker_id?:string, client_id?:string}>  $outcomes
     * @return array{
     *     schema: string,
     *     priority_adjustments: list<array<string,mixed>>,
     *     next_wave_hints: list<array<string,mixed>>,
     *     promoted: list<string>,
     *     demoted: list<string>,
     *     family_performance: list<array<string,mixed>>,
     *     give_back_risk: list<array<string,mixed>>,
     *     poison_family_hints: list<array<string,string>>,
     *     worker_fit_hints: list<array<string,mixed>>,
     *     next_wave_adjustments: list<array<string,mixed>>,
     * }
     */
    public function learn(array $outcomes): array
    {
        // Accumulate deltas by pattern_family (existing logic — unchanged).
        $accumulated  = [];
        $reasons      = [];

        // New: accumulate task_family and worker stats.
        $familyStats = [];  // [task_family => [total, delivered, give_back, proxy]]
        $workerStats = [];  // [worker_id => [task_family => [total, delivered, give_back]]]

        foreach ($outcomes as $o) {
            $family        = (string) ($o['pattern_family'] ?? '');
            $outcome       = (string) ($o['outcome'] ?? '');
            $impact        = strtolower((string) ($o['impact'] ?? self::IMPACT_MEDIUM));
            $giveBackCount = max(1, (int) ($o['give_back_count'] ?? 1));

            // ── existing pattern_family accumulation ──
            if ($family !== '') {
                $delta = match ($outcome) {
                    self::OUTCOME_DELIVERED => match ($impact) {
                        self::IMPACT_HIGH => self::DELTA_DELIVERED_HIGH,
                        self::IMPACT_LOW  => self::DELTA_DELIVERED_LOW,
                        default           => self::DELTA_DELIVERED_MEDIUM,
                    },
                    self::OUTCOME_GIVE_BACK => self::DELTA_GIVE_BACK_BASE * min($giveBackCount, 5),
                    self::OUTCOME_PROXY     => self::DELTA_PROXY,
                    default => 0.0,
                };

                $accumulated[$family] = ($accumulated[$family] ?? 0.0) + $delta;

                $reasons[$family][] = match ($outcome) {
                    self::OUTCOME_DELIVERED => "delivered:{$impact}",
                    self::OUTCOME_GIVE_BACK => "give_back:count:{$giveBackCount}",
                    self::OUTCOME_PROXY     => 'proxy:no_capability_gain',
                    default                 => "unknown_outcome:{$outcome}",
                };
            }

            // ── new task_family / worker accumulation ──
            $taskFamily = (string) ($o['task_family'] ?? '');
            $workerId   = (string) ($o['worker_id'] ?? $o['client_id'] ?? '');

            if ($taskFamily !== '') {
                if (! isset($familyStats[$taskFamily])) {
                    $familyStats[$taskFamily] = ['total' => 0, 'delivered' => 0, 'give_back' => 0, 'proxy' => 0];
                }
                $familyStats[$taskFamily]['total']++;
                match ($outcome) {
                    self::OUTCOME_DELIVERED => $familyStats[$taskFamily]['delivered']++,
                    self::OUTCOME_GIVE_BACK => $familyStats[$taskFamily]['give_back']++,
                    self::OUTCOME_PROXY     => $familyStats[$taskFamily]['proxy']++,
                    default => null,
                };

                if ($workerId !== '') {
                    if (! isset($workerStats[$workerId][$taskFamily])) {
                        $workerStats[$workerId][$taskFamily] = ['total' => 0, 'delivered' => 0, 'give_back' => 0];
                    }
                    $workerStats[$workerId][$taskFamily]['total']++;
                    match ($outcome) {
                        self::OUTCOME_DELIVERED => $workerStats[$workerId][$taskFamily]['delivered']++,
                        self::OUTCOME_GIVE_BACK => $workerStats[$workerId][$taskFamily]['give_back']++,
                        default => null,
                    };
                }
            }
        }

        // ── Build existing output (priority_adjustments, promoted, demoted, hints) ──
        $adjustments = [];
        $promoted    = [];
        $demoted     = [];

        foreach ($accumulated as $family => $totalDelta) {
            $clamped   = max(-1.0, min(1.0, round($totalDelta, 4)));
            $reasonStr = implode(';', $reasons[$family] ?? []);

            $adjustments[] = [
                'pattern_family' => $family,
                'delta'          => $clamped,
                'reason'         => $reasonStr,
            ];

            if ($clamped >= self::PROMOTE_THRESHOLD) {
                $promoted[] = $family;
            } elseif ($clamped <= -self::PROMOTE_THRESHOLD) {
                $demoted[] = $family;
            }
        }

        $hints = $this->buildNextWaveHints($promoted, $demoted);

        // ── Build new task_family output ──
        $familyPerformance   = [];
        $giveBackRisk        = [];
        $poisonFamilyHints   = [];
        $nextWaveAdjustments = [];
        $nextBatchBudget     = [];

        foreach ($familyStats as $tf => $stats) {
            $successRate  = $stats['total'] > 0 ? round($stats['delivered'] / $stats['total'], 3) : 0.0;
            $giveBackRate = $stats['total'] > 0 ? round($stats['give_back'] / $stats['total'], 3) : 0.0;

            $familyPerformance[] = [
                'task_family'  => $tf,
                'total'        => $stats['total'],
                'delivered'    => $stats['delivered'],
                'give_back'    => $stats['give_back'],
                'proxy'        => $stats['proxy'],
                'success_rate' => $successRate,
            ];

            $riskLevel = match (true) {
                $giveBackRate >= 0.60 => 'high',
                $giveBackRate >= 0.30 => 'medium',
                default               => 'low',
            };

            $giveBackRisk[] = [
                'task_family'    => $tf,
                'risk_level'     => $riskLevel,
                'give_back_rate' => $giveBackRate,
            ];

            if ($giveBackRate >= self::POISON_RATE_THRESHOLD || $stats['give_back'] >= self::POISON_COUNT_THRESHOLD) {
                $poisonFamilyHints[] = [
                    'task_family'      => $tf,
                    'reason'           => "give_back_rate={$giveBackRate}, give_back_count={$stats['give_back']}",
                    'suggested_action' => "Reduce origination for '{$tf}' until root cause is identified; unrelated families are unaffected",
                ];
            }

            $priorityDelta = round(($successRate - 0.5) * 0.4, 4);
            $priorityDelta = max(-1.0, min(1.0, $priorityDelta));
            $nextWaveAdjustments[] = [
                'task_family'    => $tf,
                'priority_delta' => $priorityDelta,
                'reason'         => $successRate >= 0.5
                    ? "family_success_rate={$successRate}: increase next-wave allocation"
                    : "family_success_rate={$successRate}: reduce next-wave allocation",
            ];

            // AC1: next_batch_budget per family.
            // AC2: any proxy task in the family → proxy-heavy → max_count capped at 1.
            $isProxyHeavy = $stats['proxy'] > 0;
            $maxCount     = $isProxyHeavy ? 1 : max(1, min(5, (int) round($successRate * 5)));
            $minEvidenceFloor = match ($riskLevel) {
                'high'  => 0.80,
                'medium' => 0.60,
                default  => 0.40,
            };
            $riskCap = match ($riskLevel) {
                'high'  => 0.30,
                'medium' => 0.50,
                default  => 0.70,
            };
            $nextBatchBudget[] = [
                'task_family'        => $tf,
                'max_count'          => $maxCount,
                'min_evidence_floor' => $minEvidenceFloor,
                'risk_cap'           => $riskCap,
            ];
        }

        // ── Build worker_fit_hints ──
        $workerFitHints = [];
        foreach ($workerStats as $workerId => $families) {
            foreach ($families as $tf => $wStats) {
                $successRate = $wStats['total'] > 0 ? round($wStats['delivered'] / $wStats['total'], 3) : 0.0;
                $fit = match (true) {
                    $successRate >= 0.70 => 'strong',
                    $successRate < 0.30  => 'weak',
                    default              => 'neutral',
                };
                $workerFitHints[] = [
                    'worker_id'    => $workerId,
                    'task_family'  => $tf,
                    'fit'          => $fit,
                    'success_rate' => $successRate,
                ];
            }
        }

        return [
            'schema'                => self::SCHEMA,
            'priority_adjustments'  => array_values($adjustments),
            'next_wave_hints'       => $hints,
            'promoted'              => array_values($promoted),
            'demoted'               => array_values($demoted),
            'family_performance'    => $familyPerformance,
            'give_back_risk'        => $giveBackRisk,
            'poison_family_hints'   => $poisonFamilyHints,
            'worker_fit_hints'      => $workerFitHints,
            'next_wave_adjustments' => $nextWaveAdjustments,
            'next_batch_budget'     => $nextBatchBudget,
        ];
    }

    /**
     * @param  list<string>  $promoted
     * @param  list<string>  $demoted
     * @return list<array{source_category:string, focus:string, priority:float}>
     */
    private function buildNextWaveHints(array $promoted, array $demoted): array
    {
        $hints = [];

        foreach ($promoted as $family) {
            $hints[] = [
                'source_category' => 'internal_patterns',
                'focus' => "expand_{$family}_patterns_that_delivered",
                'priority' => 0.8,
            ];
        }

        foreach ($demoted as $family) {
            $hints[] = [
                'source_category' => 'atlas_journals',
                'focus' => "investigate_why_{$family}_produces_give_back_or_proxy",
                'priority' => 0.6,
            ];
        }

        return $hints;
    }
}
