<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Policy for deciding when to escalate to frontier models vs. use cheaper scaffolded execution.
 *
 * DECISION VALUES:
 *   small_model            — low complexity, clear evidence, scaffold trivially sufficient
 *   scaffolded_small_model — default; moderate complexity, scaffold covers it
 *   frontier_model         — high ambiguity, conflicting evidence, or high leverage + low scaffold confidence
 *
 * DECISION RULES (first match wins):
 *   1. frontier_available = false
 *        → scaffolded_small_model + degradation_risk (never block autonomy or require human)
 *   2. conflicting_evidence = true OR ambiguity >= high_ambiguity_threshold
 *        → frontier_model + escalation_reason
 *   3. leverage >= leverage_threshold AND scaffold_confidence < scaffold_confidence_floor
 *        → frontier_model + escalation_reason
 *   4. ambiguity < low_ambiguity_threshold AND evidence_quality >= evidence_floor
 *        AND scaffold_confidence >= scaffold_confidence_floor
 *        → small_model (anti_over_escalation = true)
 *   5. default → scaffolded_small_model (anti_over_escalation = true)
 *
 * anti_over_escalation = true whenever frontier is NOT chosen.
 *
 * DEFAULT THRESHOLDS:
 *   high_ambiguity_threshold   = 0.70
 *   low_ambiguity_threshold    = 0.30
 *   evidence_floor             = 0.70
 *   scaffold_confidence_floor  = 0.65
 *   leverage_threshold         = 0.80
 *
 * INPUT:
 *   {
 *     ambiguity_score:       float
 *     evidence_quality:      float
 *     scaffold_confidence:   float
 *     conflicting_evidence?: bool   (default false)
 *     leverage_score?:       float  (default 0.0)
 *     frontier_available?:   bool   (default true)
 *     expected_quality_delta?: float
 *     thresholds?:           { high_ambiguity_threshold, low_ambiguity_threshold,
 *                              evidence_floor, scaffold_confidence_floor, leverage_threshold }
 *   }
 *
 * OUTPUT:
 *   { schema, decision, escalation_reason?, expected_quality_delta?, anti_over_escalation, degradation_risk? }
 *
 * PURE / DETERMINISTIC / NO I/O.
 */
final class AtlasExternalBrainModelEscalationEconomyPolicy
{
    public const SCHEMA = 'atlas.external_brain.model_escalation_economy_policy.v1';

    public const DECISION_SMALL_MODEL            = 'small_model';
    public const DECISION_SCAFFOLDED_SMALL_MODEL = 'scaffolded_small_model';
    public const DECISION_FRONTIER_MODEL         = 'frontier_model';

    private const DEFAULT_HIGH_AMBIGUITY_THRESHOLD  = 0.70;
    private const DEFAULT_LOW_AMBIGUITY_THRESHOLD   = 0.30;
    private const DEFAULT_EVIDENCE_FLOOR            = 0.70;
    private const DEFAULT_SCAFFOLD_CONFIDENCE_FLOOR = 0.65;
    private const DEFAULT_LEVERAGE_THRESHOLD        = 0.80;

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function decide(array $input): array
    {
        $ambiguity          = (float) ($input['ambiguity_score'] ?? 0.0);
        $evidenceQuality    = (float) ($input['evidence_quality'] ?? 0.0);
        $scaffoldConf       = (float) ($input['scaffold_confidence'] ?? 0.0);
        $conflicting        = (bool) ($input['conflicting_evidence'] ?? false);
        $leverage           = (float) ($input['leverage_score'] ?? 0.0);
        $frontierAvailable  = (bool) ($input['frontier_available'] ?? true);
        $qualityDelta       = isset($input['expected_quality_delta'])
            ? (float) $input['expected_quality_delta']
            : null;

        $thresholds = is_array($input['thresholds'] ?? null) ? $input['thresholds'] : [];
        $hiThreshold    = (float) ($thresholds['high_ambiguity_threshold']  ?? self::DEFAULT_HIGH_AMBIGUITY_THRESHOLD);
        $loThreshold    = (float) ($thresholds['low_ambiguity_threshold']   ?? self::DEFAULT_LOW_AMBIGUITY_THRESHOLD);
        $evidFloor      = (float) ($thresholds['evidence_floor']            ?? self::DEFAULT_EVIDENCE_FLOOR);
        $scaffFloor     = (float) ($thresholds['scaffold_confidence_floor'] ?? self::DEFAULT_SCAFFOLD_CONFIDENCE_FLOOR);
        $leverageThresh = (float) ($thresholds['leverage_threshold']        ?? self::DEFAULT_LEVERAGE_THRESHOLD);

        // Rule 1: frontier unavailable → degraded scaffolded fallback.
        if (! $frontierAvailable) {
            return $this->result(
                self::DECISION_SCAFFOLDED_SMALL_MODEL,
                null,
                $qualityDelta,
                true,
                'frontier_unavailable_degraded_to_scaffold',
            );
        }

        // Rule 2: conflicting evidence or high ambiguity → frontier.
        if ($conflicting || $ambiguity >= $hiThreshold) {
            $reason = $conflicting
                ? 'conflicting_evidence_requires_frontier_synthesis'
                : sprintf('ambiguity_score_%.3f_exceeds_threshold_%.3f', $ambiguity, $hiThreshold);

            return $this->result(self::DECISION_FRONTIER_MODEL, $reason, $qualityDelta, false);
        }

        // Rule 3: high leverage + low scaffold confidence → frontier.
        if ($leverage >= $leverageThresh && $scaffoldConf < $scaffFloor) {
            $reason = sprintf(
                'leverage_%.3f_high_but_scaffold_confidence_%.3f_below_floor_%.3f',
                $leverage, $scaffoldConf, $scaffFloor,
            );

            return $this->result(self::DECISION_FRONTIER_MODEL, $reason, $qualityDelta, false);
        }

        // Rule 4: low ambiguity + strong evidence + high scaffold confidence → small_model.
        if ($ambiguity < $loThreshold && $evidenceQuality >= $evidFloor && $scaffoldConf >= $scaffFloor) {
            return $this->result(self::DECISION_SMALL_MODEL, null, $qualityDelta, true);
        }

        // Rule 5: default → scaffolded_small_model.
        return $this->result(self::DECISION_SCAFFOLDED_SMALL_MODEL, null, $qualityDelta, true);
    }

    /**
     * @return array<string,mixed>
     */
    private function result(
        string  $decision,
        ?string $escalationReason,
        ?float  $qualityDelta,
        bool    $antiOverEscalation,
        ?string $degradationRisk = null,
    ): array {
        $out = [
            'schema'               => self::SCHEMA,
            'decision'             => $decision,
            'anti_over_escalation' => $antiOverEscalation,
        ];

        if ($escalationReason !== null) {
            $out['escalation_reason'] = $escalationReason;
        }
        if ($qualityDelta !== null) {
            $out['expected_quality_delta'] = $qualityDelta;
        }
        if ($degradationRisk !== null) {
            $out['degradation_risk'] = $degradationRisk;
        }

        return $out;
    }
}
