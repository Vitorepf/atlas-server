<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Selects model tier, scaffold strictness, and batch-size options on a cost/quality Pareto front
 * so Atlas uses the cheapest cognition that achieves required quality instead of always paying for
 * the most expensive model.
 *
 * DOMINANCE RULE:
 *   Option A dominates Option B when:
 *     A.quality >= B.quality AND A.cost <= B.cost AND (A.quality > B.quality OR A.cost < B.cost)
 *
 * FRONTIER PRESERVATION:
 *   A high-cost option that is dominated on quality/cost alone is kept on the Pareto front when
 *   it carries a non-empty frontier_justification (documents the expected lift or risk reduction
 *   that justifies the extra spend).
 *
 * RECOMMENDED OPTION:
 *   From the Pareto front, the option with the highest quality/cost ratio.
 *   If all options have equal ratio, the one with lowest cost is recommended.
 *
 * MODEL-AMPLIFIER POLICY (applied when floors are set, i.e. quality_floor > 0):
 *   1. Floor-based recommendation: recommend cheapest option meeting quality, safety, autonomy floors.
 *   2. Dominated frontier dependency: frontier options without measurable expected_lift or
 *      risk_reduction are flagged when a cheaper scaffolded small model meets the floors.
 *   3. Escalation triggers: emitted when benchmark, proxy, or repair-loop checks fail for the
 *      small-model path.
 *
 * INPUT:
 *   quality_floor:    float  (default 0.0) — minimum quality threshold (activates floor policy)
 *   safety_floor:     float  (default 0.0) — minimum safety score
 *   autonomy_floor:   float  (default 0.0) — minimum autonomy score
 *   benchmark_failed: bool   (default false) — small-model benchmark check failed
 *   proxy_failed:     bool   (default false) — small-model proxy check failed
 *   repair_loop_failed: bool (default false) — small-model repair-loop check failed
 *   options: list<{
 *     option_id:                 string
 *     label?:                    string
 *     quality:                   float  (0..1)
 *     cost:                      float  (> 0, defaults to 1.0)
 *     safety?:                   float  (0..1, default 1.0)
 *     autonomy?:                 float  (0..1, default 1.0)
 *     expected_lift?:            float  (0..1, default 0.0) — measurable quality/capability lift
 *     risk_reduction?:           float  (0..1, default 0.0) — measurable risk reduction
 *     is_scaffolded_small_model?: bool  (default false)
 *     frontier_justification?:   string
 *   }>
 *
 * OUTPUT:
 *   { schema, pareto_options, dominated_options, recommended_option,
 *     quality_cost_tradeoffs, risk_notes,
 *     escalation_triggers, dominated_frontier_dependency }
 *
 * PURE / DETERMINISTIC / NO I/O.
 */
final class AtlasExternalBrainCostQualityParetoFront
{
    public const SCHEMA = 'atlas.external_brain.cost_quality_pareto_front.v1';

    /** An option's expected_lift or risk_reduction must clear this to permit escalation. */
    private const ESCALATION_QUALITY_DELTA_THRESHOLD = 0.10;

    /** An option whose retry_risk exceeds this is never recommended, no matter how good its ratio. */
    private const RETRY_RISK_CEILING = 0.50;

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function compute(array $input): array
    {
        $rawOptions      = is_array($input['options'] ?? null) ? $input['options'] : [];
        $qualityFloor    = max(0.0, (float) ($input['quality_floor']    ?? 0.0));
        $safetyFloor     = max(0.0, (float) ($input['safety_floor']     ?? 0.0));
        $autonomyFloor   = max(0.0, (float) ($input['autonomy_floor']   ?? 0.0));
        $evidenceConfidenceFloor = max(0.0, (float) ($input['evidence_confidence_floor'] ?? 0.0));
        $proxyRiskCeiling = min(1.0, max(0.0, (float) ($input['proxy_risk_ceiling'] ?? 1.0)));
        $floorsActive    = $qualityFloor > 0.0 || $safetyFloor > 0.0 || $autonomyFloor > 0.0
            || $evidenceConfidenceFloor > 0.0 || $proxyRiskCeiling < 1.0;
        $benchmarkFailed = (bool) ($input['benchmark_failed']    ?? false);
        $proxyFailed     = (bool) ($input['proxy_failed']        ?? false);
        $repairLoopFailed= (bool) ($input['repair_loop_failed']  ?? false);

        // Normalise options.
        $options = [];
        foreach ($rawOptions as $o) {
            if (! is_array($o) || ! isset($o['option_id'])) {
                continue;
            }
            $options[] = [
                'option_id'               => (string) $o['option_id'],
                'label'                   => (string) ($o['label'] ?? $o['option_id']),
                'quality'                 => max(0.0, min(1.0, (float) ($o['quality'] ?? 0.0))),
                'cost'                    => max(0.0001, (float) ($o['cost'] ?? 1.0)),
                'safety'                  => max(0.0, min(1.0, (float) ($o['safety']   ?? 1.0))),
                'autonomy'                => max(0.0, min(1.0, (float) ($o['autonomy'] ?? 1.0))),
                'expected_lift'           => max(0.0, (float) ($o['expected_lift']    ?? 0.0)),
                'risk_reduction'          => max(0.0, (float) ($o['risk_reduction']   ?? 0.0)),
                'evidence_confidence'     => max(0.0, min(1.0, (float) ($o['evidence_confidence'] ?? 1.0))),
                'proxy_risk'              => max(0.0, min(1.0, (float) ($o['proxy_risk'] ?? 0.0))),
                'latency'                 => max(0.0, (float) ($o['latency'] ?? 0.0)),
                'retry_risk'              => max(0.0, min(1.0, (float) ($o['retry_risk'] ?? 0.0))),
                'leverage'                => max(0.0, (float) ($o['leverage'] ?? 0.0)),
                'is_scaffolded_small_model' => (bool) ($o['is_scaffolded_small_model'] ?? false),
                'frontier_justification'  => isset($o['frontier_justification'])
                    ? (string) $o['frontier_justification']
                    : '',
            ];
        }

        if ($options === []) {
            return $this->emptyResult();
        }

        // Determine dominance.
        $dominated   = []; // option_id → ['dominated_by' => id, 'reason' => string]
        $paretoFront = [];

        foreach ($options as $candidate) {
            $isDominated = false;

            // Has frontier justification with measurable lift/risk_reduction → never purely
            // dominated by the quality/cost rule. A justification with no measurable backing
            // does not exempt the option — it still competes on quality/cost like any other.
            if ($candidate['frontier_justification'] !== ''
                && ($candidate['expected_lift'] >= self::ESCALATION_QUALITY_DELTA_THRESHOLD
                    || $candidate['risk_reduction'] >= self::ESCALATION_QUALITY_DELTA_THRESHOLD)
            ) {
                $paretoFront[] = $candidate;
                continue;
            }

            foreach ($options as $other) {
                if ($other['option_id'] === $candidate['option_id']) {
                    continue;
                }
                if ($this->dominates($other, $candidate)) {
                    $dominated[$candidate['option_id']] = [
                        'option_id'    => $candidate['option_id'],
                        'dominated_by' => $other['option_id'],
                        'reason'       => sprintf(
                            '%s has quality %.3f vs %.3f and cost %.4f vs %.4f',
                            $other['option_id'],
                            $other['quality'], $candidate['quality'],
                            $other['cost'], $candidate['cost'],
                        ),
                    ];
                    $isDominated = true;
                    break;
                }
            }

            if (! $isDominated) {
                $paretoFront[] = $candidate;
            }
        }

        // Quality/cost tradeoffs.
        $tradeoffs = [];
        foreach ($options as $o) {
            $tradeoffs[] = [
                'option_id'       => $o['option_id'],
                'quality_per_cost' => round($o['quality'] / $o['cost'], 6),
            ];
        }
        usort($tradeoffs, static fn (array $a, array $b): int => $b['quality_per_cost'] <=> $a['quality_per_cost']);

        // Recommended: highest leverage/latency-adjusted quality-per-cost routing score from the
        // Pareto front. With leverage=0 and latency=0 (the defaults) this reduces to the plain
        // quality/cost ratio, so callers who never set these new dimensions see identical behavior.
        // An option whose retry_risk exceeds the ceiling is never recommended, however strong its
        // routing score — a good ratio does not excuse an unreliable option.
        $recommended = null;
        $bestScore   = -1.0;
        $bestCost    = PHP_FLOAT_MAX;
        foreach ($paretoFront as $o) {
            if ($o['retry_risk'] > self::RETRY_RISK_CEILING) {
                continue;
            }
            $score = ($o['quality'] * (1.0 + $o['leverage'])) / ($o['cost'] * (1.0 + $o['latency']));
            if ($score > $bestScore || ($score === $bestScore && $o['cost'] < $bestCost)) {
                $bestScore   = $score;
                $bestCost    = $o['cost'];
                $recommended = $o['option_id'];
            }
        }

        // Unsafe small-model amplification: benchmark/proxy/repair-loop failure makes a
        // scaffolded small model untrustworthy even if it's the cheapest floor-meeting option.
        $smallModelUnsafe = $benchmarkFailed || $proxyFailed || $repairLoopFailed;

        // Quality floor failures: per-option list of which floors/ceilings it fails, so a
        // cheap-but-weak option can never be recommended silently — the reason is always visible.
        $qualityFloorFailures = [];
        if ($floorsActive) {
            foreach ($options as $o) {
                $failed = [];
                if ($o['quality'] < $qualityFloor) {
                    $failed[] = 'quality';
                }
                if ($o['safety'] < $safetyFloor) {
                    $failed[] = 'safety';
                }
                if ($o['autonomy'] < $autonomyFloor) {
                    $failed[] = 'autonomy';
                }
                if ($o['evidence_confidence'] < $evidenceConfidenceFloor) {
                    $failed[] = 'evidence_confidence';
                }
                if ($o['proxy_risk'] > $proxyRiskCeiling) {
                    $failed[] = 'proxy_risk';
                }
                if ($failed !== []) {
                    $qualityFloorFailures[] = ['option_id' => $o['option_id'], 'failed_floors' => $failed];
                }
            }
        }

        // Floor-based recommendation (model-amplifier policy).
        $floorRecommended = null;
        if ($floorsActive) {
            $floorMeeting = array_filter($options, fn (array $o): bool =>
                $o['quality']  >= $qualityFloor
                && $o['safety']  >= $safetyFloor
                && $o['autonomy'] >= $autonomyFloor
                && $o['evidence_confidence'] >= $evidenceConfidenceFloor
                && $o['proxy_risk'] <= $proxyRiskCeiling
                && $o['retry_risk'] <= self::RETRY_RISK_CEILING
                && ! ($smallModelUnsafe && $o['is_scaffolded_small_model'])
            );
            if ($floorMeeting !== []) {
                usort($floorMeeting, static fn (array $a, array $b): int => $a['cost'] <=> $b['cost']);
                $floorRecommended = reset($floorMeeting)['option_id'];
                $recommended      = $floorRecommended; // override ratio-based recommendation
            }
        }

        // Dominated frontier dependency: frontier options without measurable lift/risk_reduction
        // when a cheaper scaffolded small model meets the floors.
        $dominatedFrontierDependency = [];
        if ($floorsActive) {
            $hasScaffoldedFloorMeeting = false;
            foreach ($options as $o) {
                if ($o['is_scaffolded_small_model']
                    && $o['quality'] >= $qualityFloor
                    && $o['safety'] >= $safetyFloor
                    && $o['autonomy'] >= $autonomyFloor
                ) {
                    $hasScaffoldedFloorMeeting = true;
                    break;
                }
            }
            if ($hasScaffoldedFloorMeeting) {
                foreach ($paretoFront as $o) {
                    if ($o['frontier_justification'] !== ''
                        && $o['expected_lift'] <= 0.0
                        && $o['risk_reduction'] <= 0.0
                    ) {
                        $dominatedFrontierDependency[] = $o['option_id'];
                    }
                }
            }
        }

        // Escalation triggers: AC3 — a benchmark/proxy/repair-loop failure only escalates when
        // at least one option carries a measurable expected_lift or risk_reduction clearing the
        // threshold; otherwise the frontier spend isn't justified enough to escalate over.
        $escalationPermitted = false;
        foreach ($options as $o) {
            if ($o['expected_lift'] >= self::ESCALATION_QUALITY_DELTA_THRESHOLD
                || $o['risk_reduction'] >= self::ESCALATION_QUALITY_DELTA_THRESHOLD
            ) {
                $escalationPermitted = true;
                break;
            }
        }

        $escalationTriggers = [];
        if ($escalationPermitted) {
            if ($benchmarkFailed)  $escalationTriggers[] = 'benchmark_miss';
            if ($proxyFailed)      $escalationTriggers[] = 'proxy_leakage';
            if ($repairLoopFailed) $escalationTriggers[] = 'repair_loop_failure';
        }

        // Risk notes.
        $riskNotes = [];
        if ($paretoFront === []) {
            $riskNotes[] = 'No non-dominated options found; all options may have equal quality and cost.';
        }
        foreach ($options as $o) {
            if ($o['frontier_justification'] !== '') {
                $riskNotes[] = "Option '{$o['option_id']}' is preserved as frontier: {$o['frontier_justification']}";
            }
        }
        if (count($dominated) === count($options)) {
            $riskNotes[] = 'All options appear dominated; check for circular dominance or identical quality/cost pairs.';
        }
        if ($dominatedFrontierDependency !== []) {
            $riskNotes[] = 'Dominated frontier dependency detected: '.implode(', ', $dominatedFrontierDependency)
                .'. A scaffolded small model meets the quality/safety/autonomy floors — remove unmeasured frontier dependency.';
        }

        // Sort pareto_front for determinism (by option_id).
        usort($paretoFront, static fn (array $a, array $b): int => strcmp($a['option_id'], $b['option_id']));

        $recommendationReason = match (true) {
            $recommended === null => 'no_options_available',
            $floorRecommended !== null => "cheapest_option_meeting_all_floors:{$floorRecommended}",
            $floorsActive => 'no_option_meets_all_floors_no_recommendation_possible',
            default => "highest_leverage_adjusted_quality_per_cost_on_pareto_front:{$recommended}",
        };

        // AC3: routing_reason per pareto option — never leave a selected/kept option unexplained.
        foreach ($paretoFront as &$po) {
            if ($po['option_id'] === $recommended) {
                $po['routing_reason'] = $recommendationReason;
            } elseif ($po['retry_risk'] > self::RETRY_RISK_CEILING) {
                $po['routing_reason'] = 'excluded_from_selection:retry_risk_exceeds_ceiling';
            } elseif ($po['frontier_justification'] !== '') {
                $po['routing_reason'] = 'frontier_preserved:'.$po['frontier_justification'];
            } else {
                $po['routing_reason'] = 'on_pareto_front_not_selected';
            }
        }
        unset($po);

        return [
            'schema'                       => self::SCHEMA,
            'pareto_options'               => $paretoFront,
            'dominated_options'            => array_values($dominated),
            'recommended_option'           => $recommended,
            'floor_recommended_option'     => $floorRecommended,
            'quality_cost_tradeoffs'       => $tradeoffs,
            'risk_notes'                   => $riskNotes,
            'escalation_triggers'          => $escalationTriggers,
            'dominated_frontier_dependency' => $dominatedFrontierDependency,
            'quality_floor_failures'       => $qualityFloorFailures,
            'recommendation_reason'        => $recommendationReason,
        ];
    }

    /** @param array<string,mixed> $a @param array<string,mixed> $b */
    private function dominates(array $a, array $b): bool
    {
        // A dominates B only when A is at least as safe (no higher risk) and no more retry-prone —
        // a cheaper/higher-quality option that is also flakier never earns dominance for free.
        return $a['safety'] >= $b['safety']
            && $a['retry_risk'] <= $b['retry_risk']
            && $a['quality'] >= $b['quality']
            && $a['cost'] <= $b['cost']
            && ($a['quality'] > $b['quality'] || $a['cost'] < $b['cost']);
    }

    /** @return array<string,mixed> */
    private function emptyResult(): array
    {
        return [
            'schema'                       => self::SCHEMA,
            'pareto_options'               => [],
            'dominated_options'            => [],
            'recommended_option'           => null,
            'floor_recommended_option'     => null,
            'quality_cost_tradeoffs'       => [],
            'risk_notes'                   => [],
            'escalation_triggers'          => [],
            'dominated_frontier_dependency' => [],
            'quality_floor_failures'       => [],
            'recommendation_reason'        => 'no_options_available',
        ];
    }
}
