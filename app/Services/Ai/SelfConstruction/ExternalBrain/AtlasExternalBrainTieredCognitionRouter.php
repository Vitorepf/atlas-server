<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure tiered cognition router. Assigns each origination task to the cheapest
 * cognition tier that can handle it correctly.
 *
 * Tiers (cheapest → most capable):
 *   small_model              — clear scaffold evidence, simple type (AC2)
 *   scaffolded_small_model   — medium complexity or moderate scaffold (AC2)
 *   frontier_model           — architecture tradeoffs, conflicting evidence, novel research,
 *                              critical risk, high leverage + low scaffold, high quality delta (AC3)
 *
 * Routing priority (first match wins):
 *   1. is_conflicting_evidence === true                               → frontier_model
 *   2. risk_class === 'critical'                                      → frontier_model
 *   3. origination_type in FRONTIER_TYPES                            → frontier_model
 *   4. ambiguity_score >= AMBIGUITY_THRESHOLD (0.70)                 → frontier_model
 *   5. leverage_score >= LEVERAGE_FLOOR (0.80) AND scaffold < SCAFFOLD_STRONG (0.70)
 *                                                                     → frontier_model
 *   6. expected_quality_delta >= QUALITY_DELTA_THRESHOLD (0.40)      → frontier_model
 *   7. simple type AND scaffold >= SCAFFOLD_STRONG (0.70)            → small_model
 *   8. scaffold >= SCAFFOLD_MEDIUM (0.40)                            → scaffolded_small_model
 *   9. default                                                        → scaffolded_small_model
 *
 * AC4 — frontier fallback:
 *   When assigned_tier === frontier_model AND frontier_available === false,
 *   override to scaffolded_small_model with fallback_to_scaffolded_small_model = true.
 *
 * Anti-over-escalation: simple/clear tasks (simple type + strong scaffold + no triggers)
 * MUST NOT reach frontier.
 *
 * Pure, deterministic, no providers, no I/O.
 */
final class AtlasExternalBrainTieredCognitionRouter
{
    public const SCHEMA = 'atlas.external_brain.tiered_cognition_router.v1';

    public const TIER_SMALL      = 'small_model';
    public const TIER_SCAFFOLDED = 'scaffolded_small_model';
    public const TIER_ARENA      = 'multi_agent_arena';
    public const TIER_FRONTIER   = 'frontier_model';

    private const FRONTIER_TYPES           = ['architecture_tradeoff', 'novel_research'];
    private const SIMPLE_TYPES             = ['extraction', 'validation'];
    private const AMBIGUITY_THRESHOLD      = 0.70;
    private const SCAFFOLD_STRONG          = 0.70;
    private const SCAFFOLD_MEDIUM          = 0.40;
    private const LEVERAGE_FLOOR           = 0.80;
    private const QUALITY_DELTA_THRESHOLD  = 0.40;
    private const RISK_CLASS_CRITICAL      = 'critical';
    private const RISK_CLASS_LOW           = 'low';
    private const ARENA_AMBIGUITY_THRESHOLD = 0.35;
    private const ARENA_IMPACT_THRESHOLD    = 0.50;

    /** Below this impact_score, work counts as "low value" for the frontier-refusal guard. */
    private const LOW_VALUE_IMPACT_CEILING = 0.15;

    /** @var array<string, array{required_scaffold: string, quality_gate_expectations: list<string>}> */
    private const TIER_EXPECTATIONS = [
        self::TIER_SMALL => [
            'required_scaffold' => 'light_scaffold_optional',
            'quality_gate_expectations' => ['unit_test_required'],
        ],
        self::TIER_SCAFFOLDED => [
            'required_scaffold' => 'scaffold_required',
            'quality_gate_expectations' => ['unit_test_required', 'scope_lock_required'],
        ],
        self::TIER_ARENA => [
            'required_scaffold' => 'scaffold_required_plus_critique_panel',
            'quality_gate_expectations' => ['unit_test_required', 'scope_lock_required', 'adversarial_critique_required'],
        ],
        self::TIER_FRONTIER => [
            'required_scaffold' => 'full_context_pack_required',
            'quality_gate_expectations' => ['unit_test_required', 'scope_lock_required', 'adversarial_critique_required', 'human_or_certification_review_required'],
        ],
    ];

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function route(array $facts): array
    {
        $type               = strtolower(trim((string) ($facts['origination_type']           ?? '')));
        $scaffoldStrength   = max(0.0, min(1.0, (float) ($facts['scaffold_evidence_strength'] ?? 0.0)));
        $ambiguity          = max(0.0, min(1.0, (float) ($facts['ambiguity_score']            ?? 0.0)));
        $conflicting        = (bool) ($facts['is_conflicting_evidence']  ?? false);
        $frontierAvailable  = (bool) ($facts['frontier_available']       ?? true);
        $leverageScore      = max(0.0, min(1.0, (float) ($facts['leverage_score']             ?? 0.0)));
        $expectedQualityDelta = max(0.0, min(1.0, (float) ($facts['expected_quality_delta']   ?? 0.0)));
        $riskClass          = strtolower(trim((string) ($facts['risk_class']                  ?? '')));
        $impactScore        = max(0.0, min(1.0, (float) ($facts['impact_score']               ?? 0.0)));
        $requiresArena      = (bool) ($facts['requires_critique_arena']  ?? false);

        // Frontier refusal for explicitly-declared low-value low-risk work: only fires when the
        // caller EXPLICITLY provides both impact_score and risk_class (not merely omits them,
        // which would default to 0.0/'' and wrongly refuse the many callers who never set these
        // for a leverage- or quality-delta-driven escalation).
        $isLowValueLowRisk = array_key_exists('impact_score', $facts) && $impactScore < self::LOW_VALUE_IMPACT_CEILING
            && array_key_exists('risk_class', $facts) && $riskClass === self::RISK_CLASS_LOW;

        [$tier, $escalationReason, $frontierRefused] = $this->assignTier(
            $type, $scaffoldStrength, $ambiguity, $conflicting,
            $leverageScore, $expectedQualityDelta, $riskClass,
            $impactScore, $requiresArena, $isLowValueLowRisk,
        );

        $fallback        = false;
        $frontierUnavail = false;

        if ($tier === self::TIER_FRONTIER && ! $frontierAvailable) {
            $tier            = self::TIER_SCAFFOLDED;
            $fallback        = true;
            $frontierUnavail = true;
        }

        $expectations = self::TIER_EXPECTATIONS[$tier];

        return [
            'schema_version'                     => self::SCHEMA,
            'assigned_tier'                       => $tier,
            'escalation_reason'                  => $escalationReason,
            'reason'                             => $escalationReason,
            'fallback_to_scaffolded_small_model' => $fallback,
            'frontier_unavailable'               => $frontierUnavail,
            'frontier_refused'                    => $frontierRefused,
            'required_scaffold'                  => $expectations['required_scaffold'],
            'quality_gate_expectations'          => $expectations['quality_gate_expectations'],
            'routing_explanation'                => $this->explain($tier, $escalationReason, $fallback),
        ];
    }

    /**
     * @return array{string, string|null, bool}
     */
    private function assignTier(
        string $type,
        float $scaffold,
        float $ambiguity,
        bool $conflicting,
        float $leverageScore,
        float $expectedQualityDelta,
        string $riskClass,
        float $impactScore,
        bool $requiresArena,
        bool $isLowValueLowRisk,
    ): array {
        // 1. Conflicting evidence → frontier. Never refused: a real conflict is a safety signal,
        // not an ROI question.
        if ($conflicting) {
            return [self::TIER_FRONTIER, 'conflicting_evidence_requires_frontier_resolution', false];
        }

        // 2. Critical risk class → frontier regardless of type. Never refused.
        if ($riskClass === self::RISK_CLASS_CRITICAL) {
            return [self::TIER_FRONTIER, 'risk_class_critical_requires_frontier', false];
        }

        // 3. Frontier origination types. Never refused: these categories are inherently high-stakes.
        if (in_array($type, self::FRONTIER_TYPES, true)) {
            return [self::TIER_FRONTIER, "origination_type_$type", false];
        }

        // 4. High ambiguity → frontier. Never refused: unresolved ambiguity is a correctness risk,
        // not merely a value question.
        if ($ambiguity >= self::AMBIGUITY_THRESHOLD) {
            return [self::TIER_FRONTIER, 'ambiguity_score_exceeds_threshold', false];
        }

        // 5. High leverage with low scaffold confidence → frontier, UNLESS the caller explicitly
        // declares this work low-value and low-risk (AC3: frontier must be refused there).
        if ($leverageScore >= self::LEVERAGE_FLOOR && $scaffold < self::SCAFFOLD_STRONG) {
            if ($isLowValueLowRisk) {
                return [self::TIER_SCAFFOLDED, 'frontier_refused_low_value_low_risk_despite_high_leverage', true];
            }

            return [self::TIER_FRONTIER, 'high_leverage_low_scaffold_confidence', false];
        }

        // 6. High expected quality delta from frontier → frontier, UNLESS explicitly low-value
        // low-risk (AC3).
        if ($expectedQualityDelta >= self::QUALITY_DELTA_THRESHOLD) {
            if ($isLowValueLowRisk) {
                return [self::TIER_SCAFFOLDED, 'frontier_refused_low_value_low_risk_despite_quality_delta', true];
            }

            return [self::TIER_FRONTIER, 'expected_quality_delta_justifies_frontier', false];
        }

        // 6.5. Explicit critique-arena request, or moderate ambiguity + high impact
        // → multi-agent arena (cheaper than frontier, stronger than a lone small model).
        if ($requiresArena || ($ambiguity >= self::ARENA_AMBIGUITY_THRESHOLD && $impactScore >= self::ARENA_IMPACT_THRESHOLD)) {
            return [self::TIER_ARENA, $requiresArena ? 'critique_arena_explicitly_required' : 'moderate_ambiguity_high_impact_requires_arena', false];
        }

        // 7. Simple type with strong scaffold → small model (anti-over-escalation).
        if (in_array($type, self::SIMPLE_TYPES, true) && $scaffold >= self::SCAFFOLD_STRONG) {
            return [self::TIER_SMALL, null, false];
        }

        // 8–9. Scaffolded small model for everything else.
        return [self::TIER_SCAFFOLDED, null, false];
    }

    private function explain(string $tier, ?string $reason, bool $fallback): string
    {
        if ($fallback) {
            return "frontier_unavailable_fell_back_to_$tier";
        }
        if ($tier === self::TIER_FRONTIER) {
            return "escalated_to_frontier: $reason";
        }
        if ($tier === self::TIER_ARENA) {
            return "routed_to_multi_agent_arena: $reason";
        }

        return "assigned_$tier: sufficient_for_task";
    }

    /**
     * Task fit routing: selects tiers using task_risk, novelty, evidence_need and scaffold_available.
     * Small tier for low-risk scaffolded tasks; frontier tier only for high-novelty or high-risk
     * tasks with strong expected leverage.
     *
     * @param  array{
     *   task_risk: float,
     *   novelty: float,
     *   evidence_need: float,
     *   scaffold_available: bool,
     *   expected_leverage: float,
     * }  $input
     * @return array{selected_tier:string, fallback_tier:string, routing_reason:string}
     */
    public function taskFitRouting(array $input): array
    {
        $taskRisk = (float) ($input['task_risk'] ?? 0.0);
        $novelty = (float) ($input['novelty'] ?? 0.0);
        $evidenceNeed = (float) ($input['evidence_need'] ?? 0.0);
        $scaffoldAvailable = (bool) ($input['scaffold_available'] ?? false);
        $expectedLeverage = (float) ($input['expected_leverage'] ?? 0.0);

        $highRisk = $taskRisk >= 0.7;
        $highNovelty = $novelty >= 0.7;
        $highEvidenceNeed = $evidenceNeed >= 0.7;
        $strongLeverage = $expectedLeverage >= 0.8;

        // Frontier: high novelty or high risk with strong leverage
        if (($highNovelty || $highRisk) && $strongLeverage) {
            $selectedTier = 'frontier';
            $fallbackTier = $scaffoldAvailable ? 'scaffolded_small' : 'small';
            $routingReason = sprintf(
                'frontier_selected: task_risk=%.2f novelty=%.2f expected_leverage=%.2f',
                $taskRisk, $novelty, $expectedLeverage,
            );
        } elseif ($scaffoldAvailable && !$highRisk && !$highNovelty) {
            // Small tier for low-risk scaffolded tasks
            $selectedTier = 'small';
            $fallbackTier = 'scaffolded_small';
            $routingReason = sprintf(
                'small_tier_selected: low_risk=%.2f low_novelty=%.2f scaffold_available',
                $taskRisk, $novelty,
            );
        } else {
            // Default: scaffolded small
            $selectedTier = 'scaffolded_small';
            $fallbackTier = 'small';
            $routingReason = sprintf(
                'scaffolded_small_selected: task_risk=%.2f novelty=%.2f evidence_need=%.2f',
                $taskRisk, $novelty, $evidenceNeed,
            );
        }

        return [
            'selected_tier' => $selectedTier,
            'fallback_tier' => $fallbackTier,
            'routing_reason' => $routingReason,
        ];
    }
}
