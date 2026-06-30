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
 *   frontier_model           — architecture tradeoffs, conflicting evidence, novel research (AC3)
 *
 * Routing priority (first match wins):
 *   1. is_conflicting_evidence === true              → frontier_model (AC3)
 *   2. origination_type in FRONTIER_TYPES            → frontier_model (AC3)
 *   3. ambiguity_score >= AMBIGUITY_THRESHOLD (0.70) → frontier_model (AC3)
 *   4. simple type AND scaffold >= STRONG (0.70)     → small_model (AC2)
 *   5. scaffold >= MEDIUM (0.40)                     → scaffolded_small_model (AC2)
 *   6. default                                       → scaffolded_small_model
 *
 * AC4 — frontier fallback:
 *   When assigned_tier === frontier_model AND frontier_available === false,
 *   override to scaffolded_small_model, set fallback_to_scaffolded_small_model = true.
 *   Never over-escalate: simple/clear tasks must NEVER reach frontier.
 *
 * Pure, deterministic, no providers, no I/O.
 */
final class AtlasExternalBrainTieredCognitionRouter
{
    public const SCHEMA = 'atlas.external_brain.tiered_cognition_router.v1';

    public const TIER_SMALL      = 'small_model';
    public const TIER_SCAFFOLDED = 'scaffolded_small_model';
    public const TIER_FRONTIER   = 'frontier_model';

    private const FRONTIER_TYPES         = ['architecture_tradeoff', 'novel_research'];
    private const SIMPLE_TYPES           = ['extraction', 'validation'];
    private const AMBIGUITY_THRESHOLD    = 0.70;
    private const SCAFFOLD_STRONG        = 0.70;
    private const SCAFFOLD_MEDIUM        = 0.40;

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function route(array $facts): array
    {
        $type              = strtolower(trim((string) ($facts['origination_type']        ?? '')));
        $scaffoldStrength  = max(0.0, min(1.0, (float) ($facts['scaffold_evidence_strength'] ?? 0.0)));
        $ambiguity         = max(0.0, min(1.0, (float) ($facts['ambiguity_score']            ?? 0.0)));
        $conflicting       = (bool) ($facts['is_conflicting_evidence'] ?? false);
        $frontierAvailable = (bool) ($facts['frontier_available']      ?? true);

        [$tier, $escalationReason] = $this->assignTier($type, $scaffoldStrength, $ambiguity, $conflicting);

        $fallback          = false;
        $frontierUnavail   = false;

        if ($tier === self::TIER_FRONTIER && ! $frontierAvailable) {
            $tier              = self::TIER_SCAFFOLDED;
            $fallback          = true;
            $frontierUnavail   = true;
        }

        return [
            'schema_version'                    => self::SCHEMA,
            'assigned_tier'                     => $tier,
            'escalation_reason'                 => $escalationReason,
            'fallback_to_scaffolded_small_model' => $fallback,
            'frontier_unavailable'              => $frontierUnavail,
            'routing_explanation'               => $this->explain($tier, $escalationReason, $fallback),
        ];
    }

    /**
     * @return array{string, string|null}
     */
    private function assignTier(string $type, float $scaffold, float $ambiguity, bool $conflicting): array
    {
        // 1. Conflicting evidence → frontier (AC3).
        if ($conflicting) {
            return [self::TIER_FRONTIER, 'conflicting_evidence_requires_frontier_resolution'];
        }

        // 2. Frontier origination types (AC3).
        if (in_array($type, self::FRONTIER_TYPES, true)) {
            return [self::TIER_FRONTIER, "origination_type_$type"];
        }

        // 3. High ambiguity → frontier (AC3).
        if ($ambiguity >= self::AMBIGUITY_THRESHOLD) {
            return [self::TIER_FRONTIER, 'ambiguity_score_exceeds_threshold'];
        }

        // 4. Simple type with strong scaffold → small model (AC2, anti-over-escalation).
        if (in_array($type, self::SIMPLE_TYPES, true) && $scaffold >= self::SCAFFOLD_STRONG) {
            return [self::TIER_SMALL, null];
        }

        // 5–6. Scaffolded small model for everything else.
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

        return "assigned_$tier: sufficient_for_task";
    }
}
