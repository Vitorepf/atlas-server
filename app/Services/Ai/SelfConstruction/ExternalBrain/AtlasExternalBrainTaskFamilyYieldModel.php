<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure yield model. Measures which task families actually deliver capability value
 * and penalizes spec-heavy families that produce few resolved capability deltas.
 *
 * Input facts:
 *   families — list of {family_id, accepted_specs, resolved_capability_deltas,
 *               architecture_unlocks?, verified_wiring_changes?, documentation_commits?}.
 *
 * Yield score per family:
 *   delivery_score = resolved_capability_deltas + architecture_unlocks + verified_wiring_changes
 *   raw_yield      = delivery_score / (accepted_specs + 1)   (avoids div-by-zero)
 *
 * AC2 — Spec-bulk penalty:
 *   If accepted_specs >= SPEC_BULK_THRESHOLD (4) AND resolved_capability_deltas == 0:
 *     raw_yield × PENALTY_ZERO_DELTA (0.10)  — pure spec factory.
 *   Else if accepted_specs > 0 AND delta_ratio < DELTA_RATIO_THRESHOLD (0.20):
 *     raw_yield × PENALTY_LOW_RATIO  (0.50)  — low conversion rate.
 *   (Penalty flags are mutually exclusive; first match wins.)
 *
 * Classification:
 *   yield >= HIGH_THRESHOLD  (0.70) → high_yield
 *   yield >= MID_THRESHOLD   (0.30) → moderate_yield
 *   else                            → low_yield
 *
 * Output: family_yields, ranked_families, low_yield_families, summary.
 *
 * Pure, deterministic, no providers, no I/O.
 */
final class AtlasExternalBrainTaskFamilyYieldModel
{
    public const SCHEMA = 'atlas.external_brain.task_family_yield_model.v1';

    private const SPEC_BULK_THRESHOLD  = 4;
    private const DELTA_RATIO_THRESHOLD = 0.20;
    private const PENALTY_ZERO_DELTA   = 0.10;
    private const PENALTY_LOW_RATIO    = 0.50;
    private const HIGH_THRESHOLD       = 0.70;
    private const MID_THRESHOLD        = 0.30;

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function model(array $facts): array
    {
        $rawFamilies = is_array($facts['families'] ?? null) ? $facts['families'] : [];

        $familyYields   = [];
        $lowYield       = [];
        $counts         = ['high_yield' => 0, 'moderate_yield' => 0, 'low_yield' => 0];

        foreach ($rawFamilies as $raw) {
            $familyId     = (string) ($raw['family_id'] ?? '');
            $acceptedSpecs = max(0, (int) ($raw['accepted_specs']              ?? 0));
            $deltas        = max(0, (int) ($raw['resolved_capability_deltas']  ?? 0));
            $unlocks       = max(0, (int) ($raw['architecture_unlocks']        ?? 0));
            $wiring        = max(0, (int) ($raw['verified_wiring_changes']     ?? 0));

            $deliveryScore = $deltas + $unlocks + $wiring;
            $rawYield      = round($deliveryScore / ($acceptedSpecs + 1), 6);

            // AC2: spec-bulk penalties.
            $penaltyApplied = null;
            if ($acceptedSpecs >= self::SPEC_BULK_THRESHOLD && $deltas === 0) {
                $rawYield      = round($rawYield * self::PENALTY_ZERO_DELTA, 6);
                $penaltyApplied = 'zero_delta_penalty';
            } elseif ($acceptedSpecs > 0 && ($deltas / $acceptedSpecs) < self::DELTA_RATIO_THRESHOLD) {
                $rawYield      = round($rawYield * self::PENALTY_LOW_RATIO, 6);
                $penaltyApplied = 'low_ratio_penalty';
            }

            $yieldScore     = max(0.0, min(1.0, $rawYield));
            $classification = $this->classify($yieldScore);
            $counts[$classification]++;

            $familyYields[] = [
                'family_id'      => $familyId,
                'yield_score'    => round($yieldScore, 4),
                'classification' => $classification,
                'penalty_applied' => $penaltyApplied,
            ];

            if ($classification === 'low_yield') {
                $lowYield[] = [
                    'family_id' => $familyId,
                    'reason'    => $penaltyApplied ?? 'insufficient_delivery',
                ];
            }
        }

        // Sort by yield descending.
        usort($familyYields, static fn ($a, $b) => $b['yield_score'] <=> $a['yield_score']);
        $rankedFamilies = array_column($familyYields, 'family_id');

        return [
            'schema_version'    => self::SCHEMA,
            'family_yields'     => $familyYields,
            'ranked_families'   => $rankedFamilies,
            'low_yield_families' => $lowYield,
            'summary'           => [
                'total_families' => count($rawFamilies),
                'high_yield'     => $counts['high_yield'],
                'moderate_yield' => $counts['moderate_yield'],
                'low_yield'      => $counts['low_yield'],
            ],
        ];
    }

    private function classify(float $yield): string
    {
        if ($yield >= self::HIGH_THRESHOLD) {
            return 'high_yield';
        }
        if ($yield >= self::MID_THRESHOLD) {
            return 'moderate_yield';
        }

        return 'low_yield';
    }
}
