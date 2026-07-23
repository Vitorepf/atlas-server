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
 *   delivered  — task completed and committed; positive signal.
 *   give_back  — worker returned the task; negative signal weighted by give_back_count.
 *   proxy      — task shipped but was proxy/filler (no real capability gain); stronger negative.
 *   poison     — task is actively harmful or systemically broken; triggers repair recommendation.
 *   quarantine — task family must be isolated; triggers self_heal recommendation.
 *
 * IMPACT (for delivered outcomes): high | medium | low — scales the positive delta.
 *
 * OUTPUT:
 *   { schema, priority_adjustments:list<Adjustment>, next_wave_hints:list<Hint>,
 *     promoted:list<string>, demoted:list<string>,
 *     family_performance:list<FamilyPerf>, give_back_risk:list<GiveBackRisk>,
 *     poison_family_hints:list<PoisonHint>, worker_fit_hints:list<WorkerFit>,
 *     next_wave_adjustments:list<WaveAdj>, recommendations:list<Recommendation> }
 *
 * Adjustment:     { pattern_family, delta:float[-1.0..+1.0], reason:string }
 * Hint:           { source_category:string, focus:string, priority:float[0..1] }
 * FamilyPerf:     { task_family, total, delivered, give_back, proxy, success_rate }
 * GiveBackRisk:   { task_family, risk_level:'low'|'medium'|'high', give_back_rate }
 * PoisonHint:     { task_family, reason, suggested_action }
 * WorkerFit:      { worker_id, task_family, fit:'strong'|'weak'|'neutral', success_rate }
 * WaveAdj:        { task_family, priority_delta:float, reason:string }
 * Recommendation: { task_family, action:'promote'|'avoid'|'repair'|'self_heal', confidence:float, reason:string }
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

    public const OUTCOME_POISON     = 'poison';

    public const OUTCOME_QUARANTINE = 'quarantine';

    public const IMPACT_HIGH   = 'high';

    public const IMPACT_MEDIUM = 'medium';

    public const IMPACT_LOW    = 'low';

    /** Base deltas before per-outcome scaling. */
    private const DELTA_DELIVERED_HIGH   = +0.30;

    private const DELTA_DELIVERED_MEDIUM = +0.15;

    private const DELTA_DELIVERED_LOW    = +0.05;

    private const DELTA_GIVE_BACK_BASE   = -0.20;

    private const DELTA_PROXY            = -0.40;

    private const DELTA_POISON           = -0.60;

    private const DELTA_QUARANTINE       = -0.80;

    /** Minimum absolute delta before a pattern is considered promoted/demoted. */
    private const PROMOTE_THRESHOLD = 0.01;

    /** Poison threshold: give_back_rate ≥ this OR give_back_count ≥ 3 */
    private const POISON_RATE_THRESHOLD = 0.60;

    private const POISON_COUNT_THRESHOLD = 3;

    /** Recency multiplier applied to a pattern_family delta — unspecified recency = full weight. */
    private const RECENCY_WEIGHT = [
        'fresh' => 1.0,
        'recent' => 0.7,
        'stale' => 0.3,
    ];

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

        // Confidence tracking: a single noisy low/medium delivered outcome must never promote
        // a family, but a single high-impact outcome is still enough signal to promote.
        $deliveredCount            = [];
        $deliveredHighImpactCount  = [];
        $highImpactValueProofCount = [];

        // New: accumulate task_family and worker stats.
        $familyStats = [];  // [task_family => [total, delivered, give_back, proxy]]
        $workerStats = [];  // [worker_id => [task_family => [total, delivered, give_back]]]
        $familyGiveBackWorkers = []; // [task_family => set of worker_ids with >=1 give_back]

        foreach ($outcomes as $o) {
            $family        = (string) ($o['pattern_family'] ?? '');
            $outcome       = (string) ($o['outcome'] ?? '');
            $impact        = strtolower((string) ($o['impact'] ?? self::IMPACT_MEDIUM));
            $giveBackCount = max(1, (int) ($o['give_back_count'] ?? 1));

            // ── existing pattern_family accumulation ──
            if ($family !== '') {
                $delta = match ($outcome) {
                    self::OUTCOME_DELIVERED  => match ($impact) {
                        self::IMPACT_HIGH => self::DELTA_DELIVERED_HIGH,
                        self::IMPACT_LOW  => self::DELTA_DELIVERED_LOW,
                        default           => self::DELTA_DELIVERED_MEDIUM,
                    },
                    self::OUTCOME_GIVE_BACK  => self::DELTA_GIVE_BACK_BASE * min($giveBackCount, 5),
                    self::OUTCOME_PROXY      => self::DELTA_PROXY,
                    self::OUTCOME_POISON     => self::DELTA_POISON,
                    self::OUTCOME_QUARANTINE => self::DELTA_QUARANTINE,
                    default => 0.0,
                };

                $recency = strtolower((string) ($o['recency'] ?? 'fresh'));
                $delta *= self::RECENCY_WEIGHT[$recency] ?? 1.0;

                $accumulated[$family] = ($accumulated[$family] ?? 0.0) + $delta;

                if ($outcome === self::OUTCOME_DELIVERED) {
                    $deliveredCount[$family] = ($deliveredCount[$family] ?? 0) + 1;
                    if ($impact === self::IMPACT_HIGH) {
                        $deliveredHighImpactCount[$family] = ($deliveredHighImpactCount[$family] ?? 0) + 1;
                        if ((bool) ($o['value_proof'] ?? false)) {
                            $highImpactValueProofCount[$family] = ($highImpactValueProofCount[$family] ?? 0) + 1;
                        }
                    }
                }

                $reasons[$family][] = match ($outcome) {
                    self::OUTCOME_DELIVERED  => "delivered:{$impact}",
                    self::OUTCOME_GIVE_BACK  => "give_back:count:{$giveBackCount}",
                    self::OUTCOME_PROXY      => 'proxy:no_capability_gain',
                    self::OUTCOME_POISON     => 'poison:harmful_task',
                    self::OUTCOME_QUARANTINE => 'quarantine:family_isolated',
                    default                  => "unknown_outcome:{$outcome}",
                };
            }

            // ── new task_family / worker accumulation ──
            $taskFamily = (string) ($o['task_family'] ?? '');
            $workerId   = (string) ($o['worker_id'] ?? $o['client_id'] ?? '');

            if ($taskFamily !== '') {
                if (! isset($familyStats[$taskFamily])) {
                    $familyStats[$taskFamily] = ['total' => 0, 'delivered' => 0, 'give_back' => 0, 'proxy' => 0, 'poison' => 0, 'quarantine' => 0];
                }
                $familyStats[$taskFamily]['total']++;
                match ($outcome) {
                    self::OUTCOME_DELIVERED  => $familyStats[$taskFamily]['delivered']++,
                    self::OUTCOME_GIVE_BACK  => $familyStats[$taskFamily]['give_back']++,
                    self::OUTCOME_PROXY      => $familyStats[$taskFamily]['proxy']++,
                    self::OUTCOME_POISON     => $familyStats[$taskFamily]['poison']++,
                    self::OUTCOME_QUARANTINE => $familyStats[$taskFamily]['quarantine']++,
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

                    if ($outcome === self::OUTCOME_GIVE_BACK) {
                        $familyGiveBackWorkers[$taskFamily][$workerId] = true;
                    }
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

            $delivered           = $deliveredCount[$family] ?? 0;
            $deliveredHighImpact = $deliveredHighImpactCount[$family] ?? 0;
            $highImpactValidated = $highImpactValueProofCount[$family] ?? 0;
            $confidence = match (true) {
                $delivered >= 2 && $highImpactValidated >= 1 => 'high',
                $delivered >= 2 => 'medium',
                // A single high-impact delivery is only "medium" confidence when backed by
                // explicit value_proof — a lone unproven high-impact outcome never promotes alone.
                $delivered === 1 && $highImpactValidated >= 1 => 'medium',
                default => 'low',
            };

            $adjustments[] = [
                'pattern_family' => $family,
                'delta'          => $clamped,
                'reason'         => $reasonStr,
                'confidence'     => $confidence,
            ];

            // A positive delta only promotes when there is more than a single noisy
            // sample behind it, or a single high-impact outcome with explicit value proof.
            if ($clamped >= self::PROMOTE_THRESHOLD && $confidence !== 'low') {
                $promoted[] = $family;
            } elseif ($clamped <= -self::PROMOTE_THRESHOLD) {
                $demoted[] = $family;
            }
        }

        // ── Build new task_family output from one shared family index ──
        $familyPerformance   = [];
        $giveBackRisk        = [];
        $poisonFamilyHints   = [];
        $nextWaveAdjustments = [];
        $nextBatchBudget     = [];
        $recommendations     = [];
        $focusFamilies       = [];

        foreach ($familyStats as $tf => $stats) {
            $giveBackWorkerCount = count($familyGiveBackWorkers[$tf] ?? []);
            $entry = $this->buildFamilyIndexEntry($tf, $stats, $giveBackWorkerCount);

            $familyPerformance[]   = $entry['family_performance'];
            $giveBackRisk[]        = $entry['give_back_risk'];
            $nextWaveAdjustments[] = $entry['next_wave_adjustment'];
            $nextBatchBudget[]     = $entry['next_batch_budget'];
            $recommendations[]     = $entry['recommendation'];

            if ($entry['poison_hint'] !== null) {
                $poisonFamilyHints[] = $entry['poison_hint'];
            }

            if ($entry['is_focus_family']) {
                $focusFamilies[] = $tf;
            }
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

        $hints = $this->buildNextWaveHints($promoted, $demoted, $focusFamilies);

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
            'recommendations'       => $recommendations,
        ];
    }

    /**
     * Single family outcome index: builds family_performance, give_back_risk,
     * poison hint, next_wave_adjustment, next_batch_budget, and recommendation
     * from ONE pass over a family's raw stats — so these outputs can never
     * disagree about the same family's success_rate/give_back_rate/action.
     *
     * @param  array{delivered:int,give_back:int,proxy:int,poison:int,quarantine:int,total:int}  $stats
     * @return array{family_performance:array<string,mixed>, give_back_risk:array<string,mixed>, poison_hint:?array<string,string>, next_wave_adjustment:array<string,mixed>, next_batch_budget:array<string,mixed>, recommendation:array<string,mixed>, is_focus_family:bool}
     */
    private function buildFamilyIndexEntry(string $taskFamily, array $stats, int $giveBackWorkerCount = 0): array
    {
        $successRate  = $stats['total'] > 0 ? round($stats['delivered'] / $stats['total'], 3) : 0.0;
        $giveBackRate = $stats['total'] > 0 ? round($stats['give_back'] / $stats['total'], 3) : 0.0;

        $familyPerformance = [
            'task_family'  => $taskFamily,
            'total'        => $stats['total'],
            'delivered'    => $stats['delivered'],
            'give_back'    => $stats['give_back'],
            'proxy'        => $stats['proxy'],
            'poison'       => $stats['poison'],
            'quarantine'   => $stats['quarantine'],
            'success_rate' => $successRate,
        ];

        $riskLevel = match (true) {
            $giveBackRate >= 0.60 => 'high',
            $giveBackRate >= 0.30 => 'medium',
            default               => 'low',
        };

        $giveBackRisk = [
            'task_family'    => $taskFamily,
            'risk_level'     => $riskLevel,
            'give_back_rate' => $giveBackRate,
        ];

        $poisonHint = null;
        if ($giveBackRate >= self::POISON_RATE_THRESHOLD || $stats['give_back'] >= self::POISON_COUNT_THRESHOLD) {
            // AC3: distinguish a single bad worker from a systemically poisoned family —
            // give-backs concentrated in exactly one worker are attributed to that worker,
            // not blamed on the task_family shape itself.
            $attribution = $giveBackWorkerCount === 1 ? 'worker_specific' : 'family_systemic';
            $poisonHint = [
                'task_family'      => $taskFamily,
                'reason'           => "give_back_rate={$giveBackRate}, give_back_count={$stats['give_back']}",
                'suggested_action' => "Reduce origination for '{$taskFamily}' until root cause is identified; unrelated families are unaffected",
                'attribution'      => $attribution,
            ];
        }

        $priorityDelta = max(-1.0, min(1.0, round(($successRate - 0.5) * 0.4, 4)));
        $nextWaveAdjustment = [
            'task_family'    => $taskFamily,
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
        $nextBatchBudget = [
            'task_family'        => $taskFamily,
            'max_count'          => $maxCount,
            'min_evidence_floor' => $minEvidenceFloor,
            'risk_cap'           => $riskCap,
        ];

        // Recommendations: concrete policy action derived from the family's outcome mix.
        $action     = $this->deriveRecommendationAction($stats, $successRate, $giveBackRate);
        $confidence = $this->deriveRecommendationConfidence($stats, $successRate);
        $recommendation = [
            'task_family'              => $taskFamily,
            'action'                   => $action,
            'confidence'               => $confidence,
            'reason'                   => $this->deriveRecommendationReason($action, $stats, $successRate, $giveBackRate),
            // AC1: next_batch_policy_delta mirrors the next-wave priority delta so the
            // recommendation itself carries the concrete policy adjustment for this family.
            'next_batch_policy_delta'  => $priorityDelta,
        ];
        // AC2: demoted/self-heal actions carry decay + revalidation metadata so a demoted
        // family is not permanently blacklisted — it decays and can be revalidated later.
        if (in_array($action, ['avoid', 'repair', 'self_heal'], true)) {
            $recommendation['decay'] = match ($action) {
                'self_heal' => 0.90,
                'repair'    => 0.60,
                default     => 0.40,
            };
            $recommendation['revalidate_after_cycles'] = match ($action) {
                'self_heal' => 5,
                'repair'    => 3,
                default     => 2,
            };
        }

        return [
            'family_performance'   => $familyPerformance,
            'give_back_risk'       => $giveBackRisk,
            'poison_hint'          => $poisonHint,
            'next_wave_adjustment' => $nextWaveAdjustment,
            'next_batch_budget'    => $nextBatchBudget,
            'recommendation'       => $recommendation,
            'is_focus_family'      => $action === 'promote' && $confidence >= 0.70,
        ];
    }

    /**
     * @param  list<string>  $promoted
     * @param  list<string>  $demoted
     * @param  list<string>  $focusFamilies
     * @return list<array{source_category:string, focus:string, priority:float}>
     */
    private function buildNextWaveHints(array $promoted, array $demoted, array $focusFamilies = []): array
    {
        $hints = [];

        foreach ($promoted as $family) {
            $hints[] = [
                'source_category' => 'internal_patterns',
                'focus'           => "expand_{$family}_patterns_that_delivered",
                'priority'        => 0.8,
            ];
        }

        foreach ($demoted as $family) {
            $hints[] = [
                'source_category' => 'atlas_journals',
                'focus'           => "investigate_why_{$family}_produces_give_back_or_proxy",
                'priority'        => 0.6,
            ];
        }

        foreach ($focusFamilies as $family) {
            $hints[] = [
                'source_category' => 'compounding_focus',
                'focus'           => "compound_{$family}_high_confidence_green_batch",
                'priority'        => 0.95,
            ];
        }

        return $hints;
    }

    /** @param array{delivered:int,give_back:int,proxy:int,poison:int,quarantine:int,total:int} $stats */
    private function deriveRecommendationAction(array $stats, float $successRate, float $giveBackRate): string
    {
        if ($stats['quarantine'] > 0) {
            return 'self_heal';
        }
        if ($stats['poison'] > 0) {
            return 'repair';
        }
        if ($stats['proxy'] > 0) {
            return 'avoid';
        }
        if ($giveBackRate >= 0.50 || $stats['give_back'] >= 2) {
            return 'avoid';
        }
        if ($successRate >= 0.70 && $stats['delivered'] >= 2) {
            return 'promote';
        }
        return 'repair';
    }

    /** @param array{delivered:int,give_back:int,proxy:int,poison:int,quarantine:int,total:int} $stats */
    private function deriveRecommendationConfidence(array $stats, float $successRate): float
    {
        if ($stats['total'] === 0) {
            return 0.0;
        }
        $base = round($successRate, 2);
        // More evidence → confidence scales up slightly, capped at 1.0.
        $evidenceBoost = min(0.20, $stats['total'] * 0.04);
        return round(min(1.0, $base + $evidenceBoost), 4);
    }

    /** @param array{delivered:int,give_back:int,proxy:int,poison:int,quarantine:int,total:int} $stats */
    private function deriveRecommendationReason(string $action, array $stats, float $successRate, float $giveBackRate): string
    {
        return match ($action) {
            'self_heal' => "quarantine_count={$stats['quarantine']}:family_must_be_isolated_before_re-origination",
            'repair'    => $stats['poison'] > 0
                ? "poison_count={$stats['poison']}:harmful_tasks_detected_fix_root_cause_first"
                : "success_rate={$successRate}:low_delivery_confidence_requires_repair",
            'avoid'     => $stats['proxy'] > 0
                ? "proxy_count={$stats['proxy']}:no_real_capability_gain_lowers_priority"
                : "give_back_rate={$giveBackRate},give_back_count={$stats['give_back']}:repeated_negative_lowers_priority",
            'promote'   => "success_rate={$successRate},delivered={$stats['delivered']}:high_confidence_green_compound_next_batch",
            default     => "success_rate={$successRate}:insufficient_signal",
        };
    }
}
