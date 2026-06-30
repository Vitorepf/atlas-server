<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Policy for deciding when to escalate to frontier models vs. use cheaper scaffolded execution.
 *
 * DECISION VALUES:
 *   small_model                - low complexity, clear evidence, scaffold trivially sufficient
 *   small_model_with_scaffold  - default; scaffold covers the task; emits scaffold_contract
 *   scaffolded_small_model     - degraded fallback when frontier unavailable
 *   frontier_model             - high ambiguity, conflicting evidence, repeated proxy leaks,
 *                                low output confidence, or high leverage + quality delta
 *   defer_for_more_evidence    - opt-in when evidence is weak and frontier is not triggered
 *
 * DECISION RULES (first match wins):
 *   1. frontier_available = false
 *        -> scaffolded_small_model + degradation_risk (never block autonomy)
 *   1.5 quota_pressure=true OR aesthetic_preference=true with NO real quality trigger
 *        -> small_model_with_scaffold + escalation_denied signal
 *   2. conflicting_evidence OR ambiguity >= high_ambiguity_threshold
 *        OR repeated_proxy_leaks_count > proxy_leak_threshold
 *        OR output_confidence < output_confidence_floor
 *        -> frontier_model + escalation_reason (evidence-based, not preference)
 *   3. leverage >= leverage_threshold AND scaffold_confidence < scaffold_confidence_floor
 *        AND (expected_quality_delta IS NULL OR expected_quality_delta >= min_quality_delta_threshold)
 *        -> frontier_model + escalation_reason
 *   4. prefer_defer_for_weak_evidence = true AND evidence_quality < evidence_floor
 *        -> defer_for_more_evidence
 *   5. ambiguity < low_ambiguity_threshold AND evidence_quality >= evidence_floor
 *          AND scaffold_confidence >= scaffold_confidence_floor
 *        -> small_model (anti_over_escalation = true)
 *   6. default -> small_model_with_scaffold + scaffold_contract
 *
 * anti_over_escalation = true whenever frontier is NOT chosen.
 *
 * DEFAULT THRESHOLDS:
 *   high_ambiguity_threshold    = 0.70
 *   low_ambiguity_threshold     = 0.30
 *   evidence_floor              = 0.70
 *   scaffold_confidence_floor   = 0.65
 *   leverage_threshold          = 0.80
 *   min_quality_delta_threshold = 0.20
 *   proxy_leak_threshold        = 2
 *   output_confidence_floor     = 0.40
 *
 * INPUT:
 *   {
 *     ambiguity_score:                      float
 *     evidence_quality:                     float
 *     scaffold_confidence:                  float
 *     conflicting_evidence?:                bool  (default false)
 *     leverage_score?:                      float (default 0.0)
 *     frontier_available?:                  bool  (default true)
 *     expected_quality_delta?:              float (null = unconstrained on rule 3)
 *     prefer_defer_for_weak_evidence?:      bool  (default false)
 *     repeated_proxy_leaks_count?:          int   (default 0)
 *     output_confidence?:                   float (default 1.0)
 *     escalation_reason_quota_pressure?:    bool  (default false)
 *     escalation_reason_aesthetic?:         bool  (default false)
 *     thresholds?:  { high_ambiguity_threshold, low_ambiguity_threshold,
 *                     evidence_floor, scaffold_confidence_floor, leverage_threshold,
 *                     min_quality_delta_threshold, proxy_leak_threshold,
 *                     output_confidence_floor }
 *   }
 *
 * OUTPUT:
 *   { schema, decision, escalation_reason?, expected_quality_delta?, anti_over_escalation,
 *     degradation_risk?, scaffold_contract?, escalation_denied?, escalation_denied_reason? }
 *
 * PURE / DETERMINISTIC / NO I/O.
 */
final class AtlasExternalBrainModelEscalationEconomyPolicy
{
    public const SCHEMA = 'atlas.external_brain.model_escalation_economy_policy.v1';

    public const DECISION_SMALL_MODEL              = 'small_model';
    public const DECISION_SMALL_MODEL_WITH_SCAFFOLD = 'small_model_with_scaffold';
    public const DECISION_SCAFFOLDED_SMALL_MODEL   = 'scaffolded_small_model';
    public const DECISION_FRONTIER_MODEL           = 'frontier_model';
    public const DECISION_DEFER_FOR_MORE_EVIDENCE  = 'defer_for_more_evidence';

    private const DEFAULT_HIGH_AMBIGUITY_THRESHOLD    = 0.70;
    private const DEFAULT_LOW_AMBIGUITY_THRESHOLD     = 0.30;
    private const DEFAULT_EVIDENCE_FLOOR              = 0.70;
    private const DEFAULT_SCAFFOLD_CONFIDENCE_FLOOR   = 0.65;
    private const DEFAULT_LEVERAGE_THRESHOLD          = 0.80;
    private const DEFAULT_MIN_QUALITY_DELTA_THRESHOLD = 0.20;
    private const DEFAULT_PROXY_LEAK_THRESHOLD        = 2;
    private const DEFAULT_OUTPUT_CONFIDENCE_FLOOR     = 0.40;

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function decide(array $input): array
    {
        $ambiguity          = (float) ($input['ambiguity_score']    ?? 0.0);
        $evidenceQuality    = (float) ($input['evidence_quality']   ?? 0.0);
        $scaffoldConf       = (float) ($input['scaffold_confidence'] ?? 0.0);
        $conflicting        = (bool)  ($input['conflicting_evidence']             ?? false);
        $leverage           = (float) ($input['leverage_score']                   ?? 0.0);
        $frontierAvailable  = (bool)  ($input['frontier_available']               ?? true);
        $preferDefer        = (bool)  ($input['prefer_defer_for_weak_evidence']   ?? false);
        $repeatedProxyLeaks = max(0, (int)   ($input['repeated_proxy_leaks_count']         ?? 0));
        $outputConfidence   = (float) ($input['output_confidence']                ?? 1.0);
        $quotaPressure      = (bool)  ($input['escalation_reason_quota_pressure'] ?? false);
        $aestheticPref      = (bool)  ($input['escalation_reason_aesthetic']      ?? false);
        $qualityDelta       = isset($input['expected_quality_delta'])
            ? (float) $input['expected_quality_delta']
            : null;

        $thresholds      = is_array($input['thresholds'] ?? null) ? $input['thresholds'] : [];
        $hiThreshold     = (float) ($thresholds['high_ambiguity_threshold']    ?? self::DEFAULT_HIGH_AMBIGUITY_THRESHOLD);
        $loThreshold     = (float) ($thresholds['low_ambiguity_threshold']     ?? self::DEFAULT_LOW_AMBIGUITY_THRESHOLD);
        $evidFloor       = (float) ($thresholds['evidence_floor']              ?? self::DEFAULT_EVIDENCE_FLOOR);
        $scaffFloor      = (float) ($thresholds['scaffold_confidence_floor']   ?? self::DEFAULT_SCAFFOLD_CONFIDENCE_FLOOR);
        $leverageThresh  = (float) ($thresholds['leverage_threshold']          ?? self::DEFAULT_LEVERAGE_THRESHOLD);
        $minDeltaThresh  = (float) ($thresholds['min_quality_delta_threshold'] ?? self::DEFAULT_MIN_QUALITY_DELTA_THRESHOLD);
        $proxyLeakThresh = max(0, (int) ($thresholds['proxy_leak_threshold']   ?? self::DEFAULT_PROXY_LEAK_THRESHOLD));
        $outConfFloor    = (float) ($thresholds['output_confidence_floor']     ?? self::DEFAULT_OUTPUT_CONFIDENCE_FLOOR);

        $scaffoldContract = [
            'requires_test_verification' => true,
            'max_file_mutations'         => 3,
            'confidence_floor'           => $scaffFloor,
        ];

        // Rule 1: frontier unavailable -> degraded scaffolded fallback.
        if (! $frontierAvailable) {
            return $this->result(
                self::DECISION_SCAFFOLDED_SMALL_MODEL,
                null, $qualityDelta, true,
                'frontier_unavailable_degraded_to_scaffold',
            );
        }

        // Real quality triggers (used by rule 1.5 and rule 2).
        $proxyLeakFired    = $repeatedProxyLeaks > $proxyLeakThresh;
        $lowConfFired      = $outputConfidence < $outConfFloor;
        $ambiguityFired    = $ambiguity >= $hiThreshold;
        $anyRealTrigger    = $conflicting || $ambiguityFired || $proxyLeakFired || $lowConfFired;

        // Rule 1.5: deny escalation when only reason is quota pressure or aesthetics.
        if (($quotaPressure || $aestheticPref) && ! $anyRealTrigger) {
            $deniedReason = $quotaPressure ? 'quota_pressure_is_not_a_quality_signal' : 'aesthetic_preference_is_not_a_quality_signal';
            return array_merge(
                $this->result(self::DECISION_SMALL_MODEL_WITH_SCAFFOLD, null, $qualityDelta, true),
                ['escalation_denied' => true, 'escalation_denied_reason' => $deniedReason, 'scaffold_contract' => $scaffoldContract],
            );
        }

        // Rule 2: conflicting evidence, high ambiguity, repeated proxy leaks, or low output confidence.
        if ($anyRealTrigger) {
            $reason = match(true) {
                $conflicting   => 'conflicting_evidence_requires_frontier_synthesis',
                $ambiguityFired => sprintf('ambiguity_score_%.3f_exceeds_threshold_%.3f', $ambiguity, $hiThreshold),
                $proxyLeakFired => sprintf('repeated_proxy_leaks_%d_exceeds_threshold_%d', $repeatedProxyLeaks, $proxyLeakThresh),
                default         => sprintf('output_confidence_%.3f_below_floor_%.3f', $outputConfidence, $outConfFloor),
            };
            return $this->result(self::DECISION_FRONTIER_MODEL, $reason, $qualityDelta, false);
        }

        // Rule 3: high leverage + low scaffold confidence -> frontier (when quality delta justifies cost).
        $qualityDeltaJustified = $qualityDelta === null || $qualityDelta >= $minDeltaThresh;
        if ($leverage >= $leverageThresh && $scaffoldConf < $scaffFloor && $qualityDeltaJustified) {
            $reason = sprintf(
                'leverage_%.3f_high_but_scaffold_confidence_%.3f_below_floor_%.3f',
                $leverage, $scaffoldConf, $scaffFloor,
            );
            return $this->result(self::DECISION_FRONTIER_MODEL, $reason, $qualityDelta, false);
        }

        // Rule 4: defer when evidence is weak and caller opts in.
        if ($preferDefer && $evidenceQuality < $evidFloor) {
            return $this->result(self::DECISION_DEFER_FOR_MORE_EVIDENCE, null, $qualityDelta, true);
        }

        // Rule 5: low ambiguity + strong evidence + high scaffold confidence -> small_model.
        if ($ambiguity < $loThreshold && $evidenceQuality >= $evidFloor && $scaffoldConf >= $scaffFloor) {
            return $this->result(self::DECISION_SMALL_MODEL, null, $qualityDelta, true);
        }

        // Rule 6: default -> small_model_with_scaffold + scaffold_contract.
        return array_merge(
            $this->result(self::DECISION_SMALL_MODEL_WITH_SCAFFOLD, null, $qualityDelta, true),
            ['scaffold_contract' => $scaffoldContract],
        );
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
