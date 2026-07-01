<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure cost-aware partitioner that assigns model tiers to work phases:
 * small/scaffolded lanes preferred when evidence is sufficient, frontier lanes
 * require explicit ambiguity/blast-radius reasons.
 *
 * Output includes per-lane budget and downgrade path.
 *
 * NO network I/O, NO file I/O, NO provider calls.
 */
final class AtlasExternalBrainCognitiveWorkPartitioner
{
    public const SCHEMA = 'atlas.external_brain.cognitive_work_partitioner.v1';

    public const TIER_SMALL = 'small';
    public const TIER_MID = 'mid';
    public const TIER_FRONTIER = 'frontier';

    /**
     * @param  list<array{
     *   phase_id?:string,
     *   phase_type?:string,
     *   evidence_sufficient?:bool,
     *   ambiguity_level?:float,
     *   conflicting_evidence?:bool,
     *   blast_radius?:string,
     * }>  $phases
     * @return array{
     *   schema:string,
     *   assignments:list<array{
     *     phase_id:string,
     *     tier:string,
     *     budget_tokens:int,
     *     reason_codes:list<string>,
     *     fallback_plan:array{downgrade_tier:string,acceptable_quality_loss:float},
     *   }>,
     * }
     */
    public function partition(array $phases): array
    {
        $assignments = [];

        foreach ($phases as $phase) {
            $phaseId = (string) ($phase['phase_id'] ?? 'unknown');
            $phaseType = (string) ($phase['phase_type'] ?? '');
            $evidenceSufficient = (bool) ($phase['evidence_sufficient'] ?? false);
            $ambiguity = (float) ($phase['ambiguity_level'] ?? 0.0);
            $conflicting = (bool) ($phase['conflicting_evidence'] ?? false);
            $blastRadius = (string) ($phase['blast_radius'] ?? 'low');

            $reasonCodes = [];
            $tier = self::TIER_SMALL;
            $budget = 2000;

            // Low-risk phases with sufficient evidence → small tier
            if (in_array($phaseType, ['extraction', 'verification', 'critique', 'normalization'], true)) {
                if ($evidenceSufficient) {
                    $tier = self::TIER_SMALL;
                    $budget = 2000;
                    $reasonCodes[] = 'low_risk_phase:evidence_sufficient';
                } else {
                    $tier = self::TIER_MID;
                    $budget = 8000;
                    $reasonCodes[] = 'low_risk_phase:evidence_insensitive→mid';
                }
            } elseif ($phaseType === 'frontier') {
                // Frontier requires explicit reasons
                $tier = self::TIER_FRONTIER;
                $budget = 32000;

                if ($ambiguity > 0.5) {
                    $reasonCodes[] = 'ambiguity:' . round($ambiguity, 2);
                }
                if ($conflicting) {
                    $reasonCodes[] = 'conflicting_evidence';
                }
                if ($blastRadius === 'high') {
                    $reasonCodes[] = 'blast_radius:high';
                }

                // If no frontier reasons, downgrade to mid
                if (count($reasonCodes) === 0) {
                    $tier = self::TIER_MID;
                    $budget = 8000;
                    $reasonCodes[] = 'no_frontier_reason→downgraded_to_mid';
                }
            } else {
                // Default
                $tier = self::TIER_MID;
                $budget = 8000;
                $reasonCodes[] = 'default_mid_tier';
            }

            // Build fallback plan
            $fallbackPlan = match ($tier) {
                self::TIER_FRONTIER => ['downgrade_tier' => self::TIER_MID, 'acceptable_quality_loss' => 0.15],
                self::TIER_MID => ['downgrade_tier' => self::TIER_SMALL, 'acceptable_quality_loss' => 0.25],
                default => ['downgrade_tier' => self::TIER_SMALL, 'acceptable_quality_loss' => 0.0],
            };

            sort($reasonCodes, SORT_STRING);

            $assignments[] = [
                'phase_id' => $phaseId,
                'tier' => $tier,
                'budget_tokens' => $budget,
                'reason_codes' => $reasonCodes,
                'fallback_plan' => $fallbackPlan,
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'assignments' => $assignments,
        ];
    }
}
