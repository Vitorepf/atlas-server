<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

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
