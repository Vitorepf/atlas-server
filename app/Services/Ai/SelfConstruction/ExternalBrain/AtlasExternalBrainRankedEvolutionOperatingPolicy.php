<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\Support\AiValueNormalizer;

/**
 * Deterministic policy that orders brain work by the operator-defined 8-tier ranking.
 *
 * TIER ORDER (lower number = higher priority):
 *   1. task_fabric_brutal_value       — Task Fabric brutal value filter
 *   2. muscle_outcome_learning        — Muscle outcome learning
 *   3. external_brain_consolidation   — ExternalBrain consolidation / simplification
 *   4. model_amplifier                — Model amplifier
 *   5. unified_control_plane          — Unified control-plane
 *   6. queue_self_healing             — Queue self-healing
 *   7. strategic_task_graph           — Strategic task graph
 *   8. stop_go_autonomy               — 24/7 stop-go autonomy
 *
 * REJECTION CHECKS (any match → decision='reject' with deterministic reasons):
 *   template_farm     — is_template_farm=true
 *   duplicate         — is_duplicate=true
 *   proxy             — is_proxy=true
 *   give_back_risk_high — give_back_risk >= GIVE_BACK_RISK_CEILING (0.70)
 *
 * BLOCKER CONSTRAINT (AC2):
 *   An admitted candidate at tier N carries a 'why_blocked_by' note when there exists
 *   any admitted candidate at tier M < N whose is_saturated=false.
 *   If is_saturated=true the candidate is considered done and lower tiers may proceed.
 *
 * OUTPUT:
 *   ordered_decisions — admitted candidates sorted by (tier ASC, impact DESC), then
 *                       rejected candidates appended (no position assigned)
 *   summary           — admitted/rejected counts, top unresolved blocker tier
 *
 * PURE / DETERMINISTIC / NO I/O.
 */
final class AtlasExternalBrainRankedEvolutionOperatingPolicy
{
    public const SCHEMA = 'atlas.external_brain.ranked_evolution_operating_policy.v1';

    private const RANK_ORDER = [
        'task_fabric_brutal_value'     => 1,
        'muscle_outcome_learning'      => 2,
        'external_brain_consolidation' => 3,
        'model_amplifier'              => 4,
        'unified_control_plane'        => 5,
        'queue_self_healing'           => 6,
        'strategic_task_graph'         => 7,
        'stop_go_autonomy'             => 8,
    ];

    private const GIVE_BACK_RISK_CEILING = 0.70;
    private const UNKNOWN_TIER           = 99;

    public const MODE_CREATE      = 'create';
    public const MODE_CONSOLIDATE = 'consolidate';
    public const MODE_SIMPLIFY    = 'simplify';
    public const MODE_RESEARCH    = 'research';
    public const MODE_REPAIR      = 'repair';
    public const MODE_BENCHMARK   = 'benchmark';
    public const MODE_PAUSE       = 'pause';
    public const MODE_ESCALATE    = 'escalate';

    /** @var list<string> */
    public const OPERATING_MODES = [
        self::MODE_CREATE, self::MODE_CONSOLIDATE, self::MODE_SIMPLIFY, self::MODE_RESEARCH,
        self::MODE_REPAIR, self::MODE_BENCHMARK, self::MODE_PAUSE, self::MODE_ESCALATE,
    ];

    private const CREATE_BLOCK_SATURATION_FLOOR = 0.70;
    private const CREATE_BLOCK_MARGINAL_VALUE_CEILING = 0.20;

    /**
     * Choose the single next operating mode from current queue/outcome/value/risk/maturity
     * facts, ranking all 8 modes and explaining why the winner beats the runner-up.
     *
     * @param  array{
     *   queue_saturation?: float,
     *   marginal_value?: float,
     *   outcome_failure_rate?: float,
     *   complexity_debt?: float,
     *   consolidation_opportunity?: float,
     *   research_gap?: float,
     *   unproven_claims?: float,
     *   blocking_risk?: float,
     *   critical_incident?: bool,
     * }  $facts
     * @return array{schema:string, ranked_modes:list<array<string,mixed>>, chosen_mode:string, why_top_beats_runner_up:string, evidence_that_would_change_decision:string, create_blocked:bool, create_blocked_reason:?string}
     */
    public function chooseOperatingMode(array $facts): array
    {
        $queueSaturation     = $this->clamp01((float) ($facts['queue_saturation']     ?? 0.0));
        $marginalValue       = $this->clamp01((float) ($facts['marginal_value']       ?? 0.0));
        $outcomeFailureRate  = $this->clamp01((float) ($facts['outcome_failure_rate'] ?? 0.0));
        $complexityDebt      = $this->clamp01((float) ($facts['complexity_debt']      ?? 0.0));
        $consolidationOpp    = $this->clamp01((float) ($facts['consolidation_opportunity'] ?? 0.0));
        $researchGap         = $this->clamp01((float) ($facts['research_gap']         ?? 0.0));
        $unprovenClaims      = $this->clamp01((float) ($facts['unproven_claims']      ?? 0.0));
        $blockingRisk        = $this->clamp01((float) ($facts['blocking_risk']        ?? 0.0));
        $criticalIncident    = (bool) ($facts['critical_incident'] ?? false);

        // AC4: create is hard-blocked (never wins) when the queue is saturated AND
        // there is little marginal value left to create — more volume would not help.
        $createBlocked = $queueSaturation >= self::CREATE_BLOCK_SATURATION_FLOOR
            && $marginalValue <= self::CREATE_BLOCK_MARGINAL_VALUE_CEILING;
        $createBlockedReason = $createBlocked
            ? 'queue_saturation >= 0.70 and marginal_value <= 0.20: more creation would not be absorbed or would not pay off'
            : null;

        $scores = [
            self::MODE_CREATE      => $createBlocked ? 0.0 : $marginalValue * (1.0 - $queueSaturation),
            self::MODE_CONSOLIDATE => $consolidationOpp * $queueSaturation,
            self::MODE_SIMPLIFY    => $complexityDebt,
            self::MODE_RESEARCH    => $researchGap,
            self::MODE_REPAIR      => $outcomeFailureRate,
            self::MODE_BENCHMARK   => $unprovenClaims,
            self::MODE_PAUSE       => $criticalIncident ? 0.0 : $blockingRisk,
            self::MODE_ESCALATE    => $criticalIncident ? 1.0 : $blockingRisk * 0.5,
        ];

        $ranked = [];
        foreach (self::OPERATING_MODES as $mode) {
            $ranked[] = ['mode' => $mode, 'score' => round($scores[$mode], 4)];
        }
        usort($ranked, static fn (array $a, array $b): int => $b['score'] !== $a['score']
            ? $b['score'] <=> $a['score']
            : strcmp($a['mode'], $b['mode']));

        $top      = $ranked[0];
        $runnerUp = $ranked[1];
        $margin   = round($top['score'] - $runnerUp['score'], 4);

        $why = $margin > 0
            ? sprintf(
                '%s (score %.4f) beats %s (score %.4f) by a margin of %.4f based on the current facts.',
                $top['mode'], $top['score'], $runnerUp['mode'], $runnerUp['score'], $margin,
            )
            : sprintf(
                '%s and %s are tied at score %.4f; %s wins the deterministic tie-break (alphabetical).',
                $top['mode'], $runnerUp['mode'], $top['score'], $top['mode'],
            );

        $evidenceHint = sprintf(
            'if %s rose enough to exceed %.4f (current top score), %s would become the chosen mode instead.',
            $runnerUp['mode'], $top['score'], $runnerUp['mode'],
        );

        return [
            'schema'                              => self::SCHEMA,
            'ranked_modes'                        => $ranked,
            'chosen_mode'                         => $top['mode'],
            'why_top_beats_runner_up'             => $why,
            'evidence_that_would_change_decision' => $evidenceHint,
            'create_blocked'                      => $createBlocked,
            'create_blocked_reason'               => $createBlockedReason,
        ];
    }

    private function clamp01(float $v): float
    {
        return AiValueNormalizer::clampUnit($v);
    }

    public const ACTION_ORIGINATE_SELECTIVE = 'originate_selective';
    public const ACTION_CONSOLIDATE_FIRST   = 'consolidate_first';
    public const ACTION_RESEARCH            = 'research';
    public const ACTION_SELF_HEAL           = 'self_heal';
    public const ACTION_HOLD                = 'hold';

    private const SELF_HEAL_POISON_FLOOR       = 0.40;
    private const CONSOLIDATE_QUALITY_CEILING  = 0.40;
    private const CONSOLIDATE_REDUNDANCY_FLOOR = 0.50;
    private const ORIGINATE_QUEUE_HEALTH_FLOOR = 0.60;
    private const ORIGINATE_LEVERAGE_FLOOR     = 0.60;
    private const ORIGINATE_DRAIN_CEILING      = 0.60;
    private const RESEARCH_EVIDENCE_CEILING    = 0.30;

    /**
     * Chooses the brain's next posture from five mutually-exclusive actions,
     * evaluated in fail-safe priority order: a malformed/poison-risk queue is
     * repaired before any new volume is added, weak-quality/redundant queues are
     * consolidated before being grown, a healthy deep queue with a strong frontier
     * is originated into selectively, thin evidence sends the brain to research,
     * and hold is the honest fallback when no dimension clears its bar.
     *
     * @param  array{
     *   queue_health?: float,
     *   frontier_leverage?: float,
     *   task_quality?: float,
     *   redundancy?: float,
     *   worker_drain?: float,
     *   evidence_quality?: float,
     *   malformed_or_poison_risk?: float,
     * }  $facts
     * @return array{schema:string, chosen_action:string, reasons:list<string>, signals:array<string,float>}
     */
    public function decideNextPosture(array $facts): array
    {
        $queueHealth      = $this->clamp01((float) ($facts['queue_health']             ?? 0.0));
        $frontierLeverage = $this->clamp01((float) ($facts['frontier_leverage']        ?? 0.0));
        $taskQuality      = $this->clamp01((float) ($facts['task_quality']             ?? 1.0));
        $redundancy       = $this->clamp01((float) ($facts['redundancy']               ?? 0.0));
        $workerDrain      = $this->clamp01((float) ($facts['worker_drain']             ?? 0.0));
        $evidenceQuality  = $this->clamp01((float) ($facts['evidence_quality']         ?? 1.0));
        $poisonRisk       = $this->clamp01((float) ($facts['malformed_or_poison_risk'] ?? 0.0));

        if ($poisonRisk >= self::SELF_HEAL_POISON_FLOOR) {
            $action = self::ACTION_SELF_HEAL;
            $reason = sprintf(
                'malformed_or_poison_risk:%.2f>=%.2f: repair the queue before adding new volume',
                $poisonRisk, self::SELF_HEAL_POISON_FLOOR,
            );
        } elseif ($taskQuality <= self::CONSOLIDATE_QUALITY_CEILING && $redundancy >= self::CONSOLIDATE_REDUNDANCY_FLOOR) {
            $action = self::ACTION_CONSOLIDATE_FIRST;
            $reason = sprintf(
                'task_quality:%.2f<=%.2f redundancy:%.2f>=%.2f: consolidate before creating more low-quality, redundant volume',
                $taskQuality, self::CONSOLIDATE_QUALITY_CEILING, $redundancy, self::CONSOLIDATE_REDUNDANCY_FLOOR,
            );
        } elseif (
            $queueHealth >= self::ORIGINATE_QUEUE_HEALTH_FLOOR
            && $frontierLeverage >= self::ORIGINATE_LEVERAGE_FLOOR
            && $workerDrain <= self::ORIGINATE_DRAIN_CEILING
        ) {
            $action = self::ACTION_ORIGINATE_SELECTIVE;
            $reason = sprintf(
                'queue_health:%.2f>=%.2f frontier_leverage:%.2f>=%.2f worker_drain:%.2f<=%.2f: healthy deep queue with a high-value frontier, originate selectively rather than stop',
                $queueHealth, self::ORIGINATE_QUEUE_HEALTH_FLOOR, $frontierLeverage, self::ORIGINATE_LEVERAGE_FLOOR, $workerDrain, self::ORIGINATE_DRAIN_CEILING,
            );
        } elseif ($evidenceQuality <= self::RESEARCH_EVIDENCE_CEILING) {
            $action = self::ACTION_RESEARCH;
            $reason = sprintf(
                'evidence_quality:%.2f<=%.2f: gather stronger evidence before committing to origination or consolidation',
                $evidenceQuality, self::RESEARCH_EVIDENCE_CEILING,
            );
        } else {
            $action = self::ACTION_HOLD;
            $reason = 'no dimension clears its threshold: hold rather than force a low-conviction move';
        }

        return [
            'schema'        => self::SCHEMA,
            'chosen_action' => $action,
            'reasons'       => [$reason],
            'signals'       => [
                'queue_health'             => $queueHealth,
                'frontier_leverage'        => $frontierLeverage,
                'task_quality'             => $taskQuality,
                'redundancy'               => $redundancy,
                'worker_drain'             => $workerDrain,
                'evidence_quality'         => $evidenceQuality,
                'malformed_or_poison_risk' => $poisonRisk,
            ],
        ];
    }

    private const WEAK_EVIDENCE_FLOOR = 0.30;
    private const LOW_READINESS_FLOOR = 0.30;

    /**
     * Ranks candidate next-evolution moves by evidence-backed leverage rather than
     * hype: a move claiming huge impact but backed by weak evidence or that isn't
     * actually implementable yet is rejected outright, never promoted on narrative alone.
     *
     * @param  list<array<string,mixed>>  $moves
     * @return array{schema:string, ranked_moves:list<array<string,mixed>>, selected_move:?array<string,mixed>, rejected_move_reasons:array<string,list<string>>}
     */
    public function rankMoves(array $moves): array
    {
        $ranked = [];
        $rejectedReasons = [];

        foreach ($moves as $move) {
            $id = (string) ($move['id'] ?? '');
            $evidenceStrength = $this->clamp01((float) ($move['evidence_strength'] ?? 0.0));
            $leverageScore = $this->clamp01((float) ($move['leverage_score'] ?? 0.0));
            $autonomyGain = $this->clamp01((float) ($move['autonomy_gain'] ?? 0.0));
            $riskReduction = $this->clamp01((float) ($move['risk_reduction'] ?? 0.0));
            $simplificationGain = $this->clamp01((float) ($move['simplification_gain'] ?? 0.0));
            $readiness = $this->clamp01((float) ($move['readiness'] ?? 0.0));

            $reasons = [];
            if ($evidenceStrength < self::WEAK_EVIDENCE_FLOOR) {
                $reasons[] = 'weak_evidence';
            }
            if ($readiness < self::LOW_READINESS_FLOOR) {
                $reasons[] = 'low_implementability';
            }

            if ($reasons !== []) {
                $rejectedReasons[$id] = $reasons;

                continue;
            }

            $score = round(
                $evidenceStrength * 0.25
                + $leverageScore * 0.25
                + $autonomyGain * 0.20
                + $riskReduction * 0.15
                + $simplificationGain * 0.15,
                4,
            );

            $ranked[] = [
                'id' => $id,
                'score' => $score,
                'evidence_strength' => $evidenceStrength,
                'leverage_score' => $leverageScore,
                'autonomy_gain' => $autonomyGain,
                'risk_reduction' => $riskReduction,
                'simplification_gain' => $simplificationGain,
                'readiness' => $readiness,
            ];
        }

        usort($ranked, static fn (array $a, array $b): int => $a['score'] !== $b['score']
            ? $b['score'] <=> $a['score']
            : strcmp($a['id'], $b['id']));

        return [
            'schema' => self::SCHEMA,
            'ranked_moves' => $ranked,
            'selected_move' => $ranked[0] ?? null,
            'rejected_move_reasons' => $rejectedReasons,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @return array<string,mixed>
     */
    public function rank(array $candidates): array
    {
        $admitted  = [];
        $rejected  = [];

        foreach ($candidates as $candidate) {
            $id           = (string) ($candidate['id']              ?? '');
            $category     = (string) ($candidate['category']        ?? '');
            $impact       = (float)  ($candidate['impact']          ?? 0.0);
            $risk         = (float)  ($candidate['risk']            ?? 0.0);
            $giveBackRisk = (float)  ($candidate['give_back_risk']  ?? 0.0);
            $isTemplate   = (bool)   ($candidate['is_template_farm'] ?? false);
            $isDuplicate  = (bool)   ($candidate['is_duplicate']    ?? false);
            $isProxy      = (bool)   ($candidate['is_proxy']        ?? false);
            $isSaturated  = (bool)   ($candidate['is_saturated']    ?? false);

            $tier = self::RANK_ORDER[$category] ?? self::UNKNOWN_TIER;

            $reasons = [];
            if ($isTemplate)                             $reasons[] = 'template_farm';
            if ($isDuplicate)                            $reasons[] = 'duplicate';
            if ($isProxy)                                $reasons[] = 'proxy';
            if ($giveBackRisk >= self::GIVE_BACK_RISK_CEILING) $reasons[] = 'give_back_risk_high';

            $entry = [
                'id'                => $id,
                'category'          => $category,
                'rank_tier'         => $tier,
                'impact'            => $impact,
                'risk'              => $risk,
                'is_saturated'      => $isSaturated,
                'rejection_reasons' => $reasons,
            ];

            if ($reasons !== []) {
                $rejected[] = array_merge($entry, ['decision' => 'reject', 'why_blocked_by' => null]);
            } else {
                $admitted[] = array_merge($entry, ['decision' => 'admit']);
            }
        }

        // Sort admitted: tier ASC, then impact DESC (deterministic tie-break: id ASC)
        usort($admitted, static function (array $a, array $b): int {
            if ($a['rank_tier'] !== $b['rank_tier']) {
                return $a['rank_tier'] <=> $b['rank_tier'];
            }
            if ($a['impact'] !== $b['impact']) {
                return $b['impact'] <=> $a['impact'];
            }

            return strcmp($a['id'], $b['id']);
        });

        // Apply AC2 blocker constraint: track the lowest-tier unresolved (non-saturated) item
        $lowestUnsaturatedTier     = null;
        $lowestUnsaturatedCategory = null;
        $position                  = 1;
        $finalAdmitted             = [];

        foreach ($admitted as $item) {
            $tier = $item['rank_tier'];

            // If a higher-priority (lower-tier-number) unresolved item exists, explain why this waits
            $whyBlockedBy = null;
            if ($lowestUnsaturatedTier !== null && $tier > $lowestUnsaturatedTier) {
                $whyBlockedBy = sprintf(
                    'tier-%d (%s) is unresolved and not saturated; resolve or mark saturated before promoting this candidate',
                    $lowestUnsaturatedTier,
                    $lowestUnsaturatedCategory,
                );
            }

            // Update tracker: only non-saturated items count as blockers
            if (! $item['is_saturated'] && ($lowestUnsaturatedTier === null || $tier < $lowestUnsaturatedTier)) {
                $lowestUnsaturatedTier     = $tier;
                $lowestUnsaturatedCategory = $item['category'];
            }

            $finalAdmitted[] = array_merge($item, [
                'position'       => $position++,
                'why_blocked_by' => $whyBlockedBy,
            ]);
        }

        return [
            'schema'            => self::SCHEMA,
            'ordered_decisions' => array_merge($finalAdmitted, $rejected),
            'summary'           => [
                'admitted'             => count($finalAdmitted),
                'rejected'             => count($rejected),
                'top_blocker_tier'     => $lowestUnsaturatedTier,
                'top_blocker_category' => $lowestUnsaturatedCategory,
            ],
        ];
    }
}
