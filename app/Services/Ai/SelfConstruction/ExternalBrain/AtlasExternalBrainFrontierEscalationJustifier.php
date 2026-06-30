<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure justifier. Evaluates a task's complexity signals and produces an explicit
 * justification chain for whether frontier escalation is warranted.
 *
 * AC2 — frontier escalation justified when any hold:
 *   - ambiguity_score        >= AMBIGUITY_THRESHOLD (0.65)
 *   - blast_radius           >= BLAST_THRESHOLD     (0.60)
 *   - is_conflicting_evidence === true
 *
 * AC3 — scaffolded small-model sufficient when all hold:
 *   - evidence_strength      >= EVIDENCE_THRESHOLD  (0.70)
 *   - task_classification in KNOWN_TYPES
 *   - ambiguity_score        <  LOW_AMBIGUITY        (0.35)
 *
 * Recommended tier (priority):
 *   1. Escalation reasons present AND cost_ratio <= MAX_COST_RATIO (5.0) → frontier_model
 *   2. Escalation reasons present AND cost_ratio > MAX_COST_RATIO        → scaffolded_small_model (cost-unjustified)
 *   3. All small-model-sufficiency conditions met                         → small_model
 *   4. Default                                                            → scaffolded_small_model
 *
 * AC4 outputs: recommended_tier, escalation_reasons, small_model_sufficiency_reasons,
 *   frontier_cost_justification, confidence.
 *
 * Pure, deterministic, no providers, no I/O.
 */
final class AtlasExternalBrainFrontierEscalationJustifier
{
    public const SCHEMA = 'atlas.external_brain.frontier_escalation_justifier.v1';

    private const AMBIGUITY_THRESHOLD = 0.65;
    private const BLAST_THRESHOLD     = 0.60;
    private const EVIDENCE_THRESHOLD  = 0.70;
    private const LOW_AMBIGUITY       = 0.35;
    private const MAX_COST_RATIO      = 5.0;
    private const EXPECTED_LIFT_THRESHOLD = 0.60;
    private const ARCHITECTURAL_LEVERAGE_THRESHOLD = 0.60;

    public const DECISION_FRONTIER_REQUIRED = 'frontier_required';
    public const DECISION_USE_SCAFFOLDED_STANDARD_MODEL = 'use_scaffolded_standard_model';

    private const KNOWN_TYPES = ['known', 'routine', 'deterministic', 'extraction', 'validation'];

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function justify(array $facts): array
    {
        $ambiguity         = max(0.0, min(1.0, (float) ($facts['ambiguity_score']               ?? 0.0)));
        $blastRadius       = max(0.0, min(1.0, (float) ($facts['blast_radius']                  ?? 0.0)));
        $conflicting       = (bool)              ($facts['is_conflicting_evidence']              ?? false);
        $evidenceStrength  = max(0.0, min(1.0, (float) ($facts['evidence_strength']              ?? 0.0)));
        $taskClass         = strtolower(trim((string) ($facts['task_classification']            ?? '')));
        $frontierCostUnits = max(0.0, (float) ($facts['estimated_frontier_cost_units'] ?? 1.0));
        $smallCostUnits    = max(0.0, (float) ($facts['estimated_small_cost_units']    ?? 1.0));
        $expectedLift      = max(0.0, min(1.0, (float) ($facts['expected_lift']                   ?? 0.0)));
        $architecturalLeverage = max(0.0, min(1.0, (float) ($facts['architectural_leverage_score'] ?? 0.0)));

        // AC2: escalation reasons.
        $escalationReasons = [];
        if ($ambiguity >= self::AMBIGUITY_THRESHOLD) {
            $escalationReasons[] = 'high_ambiguity_score';
        }
        if ($blastRadius >= self::BLAST_THRESHOLD) {
            $escalationReasons[] = 'high_blast_radius';
        }
        if ($conflicting) {
            $escalationReasons[] = 'conflicting_evidence_requires_synthesis';
        }
        if ($expectedLift >= self::EXPECTED_LIFT_THRESHOLD) {
            $escalationReasons[] = 'high_expected_lift';
        }
        if ($architecturalLeverage >= self::ARCHITECTURAL_LEVERAGE_THRESHOLD) {
            $escalationReasons[] = 'high_architectural_leverage';
        }

        // AC3: small-model sufficiency reasons.
        $smallModelReasons = [];
        if ($evidenceStrength >= self::EVIDENCE_THRESHOLD) {
            $smallModelReasons[] = 'strong_scaffold_evidence';
        }
        if (in_array($taskClass, self::KNOWN_TYPES, true)) {
            $smallModelReasons[] = 'task_classification_is_known';
        }
        if ($ambiguity < self::LOW_AMBIGUITY) {
            $smallModelReasons[] = 'low_ambiguity_score';
        }
        $isSmallSufficient = count($smallModelReasons) === 3; // all three must hold

        // Cost ratio.
        $costRatio = $smallCostUnits > 0.0 ? round($frontierCostUnits / $smallCostUnits, 4) : 1.0;
        $costJustified = ! empty($escalationReasons) && $costRatio <= self::MAX_COST_RATIO;
        $costExplanation = ! empty($escalationReasons)
            ? ($costJustified
                ? "frontier cost ratio ($costRatio) within acceptable bound (" . self::MAX_COST_RATIO . ')'
                : "frontier cost ratio ($costRatio) exceeds bound (" . self::MAX_COST_RATIO . '); downgrade recommended')
            : 'no_escalation_triggers_frontier_not_warranted';

        // Recommended tier.
        if (! empty($escalationReasons) && $costJustified) {
            $tier = 'frontier_model';
        } elseif (! empty($escalationReasons)) {
            $tier = 'scaffolded_small_model'; // cost-unjustified escalation → downgrade
        } elseif ($isSmallSufficient) {
            $tier = 'small_model';
        } else {
            $tier = 'scaffolded_small_model';
        }

        // Confidence.
        $confidence = $this->confidence(! empty($escalationReasons), $isSmallSufficient, $costJustified);

        // AC2/AC3/AC4: canonical decision vocabulary + threshold evidence + fallback route.
        // frontier_required only when escalation triggers AND cost is justified — never on
        // escalation triggers alone, so a cost-unjustified escalation correctly falls back.
        $decision = ($tier === 'frontier_model') ? self::DECISION_FRONTIER_REQUIRED : self::DECISION_USE_SCAFFOLDED_STANDARD_MODEL;
        $fallbackRoute = $decision === self::DECISION_FRONTIER_REQUIRED ? null : $tier;

        $thresholdEvidence = [
            ['factor' => 'ambiguity_score', 'value' => $ambiguity, 'threshold' => self::AMBIGUITY_THRESHOLD, 'crossed' => $ambiguity >= self::AMBIGUITY_THRESHOLD],
            ['factor' => 'blast_radius', 'value' => $blastRadius, 'threshold' => self::BLAST_THRESHOLD, 'crossed' => $blastRadius >= self::BLAST_THRESHOLD],
            ['factor' => 'expected_lift', 'value' => $expectedLift, 'threshold' => self::EXPECTED_LIFT_THRESHOLD, 'crossed' => $expectedLift >= self::EXPECTED_LIFT_THRESHOLD],
            ['factor' => 'architectural_leverage_score', 'value' => $architecturalLeverage, 'threshold' => self::ARCHITECTURAL_LEVERAGE_THRESHOLD, 'crossed' => $architecturalLeverage >= self::ARCHITECTURAL_LEVERAGE_THRESHOLD],
            ['factor' => 'is_conflicting_evidence', 'value' => $conflicting, 'threshold' => true, 'crossed' => $conflicting],
        ];

        return [
            'schema_version'                  => self::SCHEMA,
            'recommended_tier'                => $tier,
            'escalation_reasons'              => $escalationReasons,
            'small_model_sufficiency_reasons' => $smallModelReasons,
            'frontier_cost_justification'     => [
                'justified'   => $costJustified,
                'cost_ratio'  => $costRatio,
                'explanation' => $costExplanation,
            ],
            'confidence'                      => $confidence,
            'decision'                        => $decision,
            'threshold_evidence'              => $thresholdEvidence,
            'escalation_reason'               => $escalationReasons === [] ? 'none' : implode('; ', $escalationReasons),
            'fallback_route'                  => $fallbackRoute,
            'provider_specific_dependency'    => false,
        ];
    }

    private function confidence(bool $hasEscalation, bool $isSmallSufficient, bool $costOk): string
    {
        if ($hasEscalation && $costOk) {
            return 'high'; // clear escalation case
        }
        if (! $hasEscalation && $isSmallSufficient) {
            return 'high'; // clear small-model case
        }

        return 'medium'; // ambiguous or cost-overridden
    }
}
