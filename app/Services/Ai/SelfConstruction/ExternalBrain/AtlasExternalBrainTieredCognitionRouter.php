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
    private const ARENA_AMBIGUITY_THRESHOLD = 0.35;
    private const ARENA_IMPACT_THRESHOLD    = 0.50;

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

        [$tier, $escalationReason] = $this->assignTier(
            $type, $scaffoldStrength, $ambiguity, $conflicting,
            $leverageScore, $expectedQualityDelta, $riskClass,
            $impactScore, $requiresArena,
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
            'assigned_tier'                      => $tier,
            'escalation_reason'                  => $escalationReason,
            'reason'                             => $escalationReason,
            'fallback_to_scaffolded_small_model' => $fallback,
            'frontier_unavailable'               => $frontierUnavail,
            'required_scaffold'                  => $expectations['required_scaffold'],
            'quality_gate_expectations'          => $expectations['quality_gate_expectations'],
            'routing_explanation'                => $this->explain($tier, $escalationReason, $fallback),
        ];
    }

    /**
     * @return array{string, string|null}
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
    ): array {
        // 1. Conflicting evidence → frontier.
        if ($conflicting) {
            return [self::TIER_FRONTIER, 'conflicting_evidence_requires_frontier_resolution'];
        }

        // 2. Critical risk class → frontier regardless of type.
        if ($riskClass === self::RISK_CLASS_CRITICAL) {
            return [self::TIER_FRONTIER, 'risk_class_critical_requires_frontier'];
        }

        // 3. Frontier origination types.
        if (in_array($type, self::FRONTIER_TYPES, true)) {
            return [self::TIER_FRONTIER, "origination_type_$type"];
        }

        // 4. High ambiguity → frontier.
        if ($ambiguity >= self::AMBIGUITY_THRESHOLD) {
            return [self::TIER_FRONTIER, 'ambiguity_score_exceeds_threshold'];
        }

        // 5. High leverage with low scaffold confidence → frontier.
        if ($leverageScore >= self::LEVERAGE_FLOOR && $scaffold < self::SCAFFOLD_STRONG) {
            return [self::TIER_FRONTIER, 'high_leverage_low_scaffold_confidence'];
        }

        // 6. High expected quality delta from frontier → frontier.
        if ($expectedQualityDelta >= self::QUALITY_DELTA_THRESHOLD) {
            return [self::TIER_FRONTIER, 'expected_quality_delta_justifies_frontier'];
        }

        // 6.5. Explicit critique-arena request, or moderate ambiguity + high impact
        // → multi-agent arena (cheaper than frontier, stronger than a lone small model).
        if ($requiresArena || ($ambiguity >= self::ARENA_AMBIGUITY_THRESHOLD && $impactScore >= self::ARENA_IMPACT_THRESHOLD)) {
            return [self::TIER_ARENA, $requiresArena ? 'critique_arena_explicitly_required' : 'moderate_ambiguity_high_impact_requires_arena'];
        }

        // 7. Simple type with strong scaffold → small model (anti-over-escalation).
        if (in_array($type, self::SIMPLE_TYPES, true) && $scaffold >= self::SCAFFOLD_STRONG) {
            return [self::TIER_SMALL, null];
        }

        // 8–9. Scaffolded small model for everything else.
        return [self::TIER_SCAFFOLDED, null];
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
}
