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

    private const SPEC_BULK_THRESHOLD    = 4;
    private const DELTA_RATIO_THRESHOLD  = 0.20;
    private const PENALTY_ZERO_DELTA     = 0.10;
    private const PENALTY_LOW_RATIO      = 0.50;
    private const HIGH_THRESHOLD         = 0.70;
    private const MID_THRESHOLD          = 0.30;
    private const GIVE_BACK_DOWNRANK_FLOOR = 0.30;
    private const HIGH_CONFIDENCE_SPECS  = 5;
    private const MID_CONFIDENCE_SPECS   = 2;

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
            $familyId      = (string) ($raw['family_id'] ?? '');
            $acceptedSpecs = max(0, (int)   ($raw['accepted_specs']              ?? 0));
            $deltas        = max(0, (int)   ($raw['resolved_capability_deltas']  ?? 0));
            $unlocks       = max(0, (int)   ($raw['architecture_unlocks']        ?? 0));
            $wiring        = max(0, (int)   ($raw['verified_wiring_changes']     ?? 0));
            $giveBackRate  = max(0.0, min(1.0, (float) ($raw['give_back_rate']   ?? 0.0)));

            $deliveryScore = $deltas + $unlocks + $wiring;
            $rawYield      = round($deliveryScore / ($acceptedSpecs + 1), 6);

            // AC2: spec-bulk penalties.
            $penaltyApplied = null;
            if ($acceptedSpecs >= self::SPEC_BULK_THRESHOLD && $deltas === 0) {
                $rawYield       = round($rawYield * self::PENALTY_ZERO_DELTA, 6);
                $penaltyApplied = 'zero_delta_penalty';
            } elseif ($acceptedSpecs > 0 && ($deltas / $acceptedSpecs) < self::DELTA_RATIO_THRESHOLD) {
                $rawYield       = round($rawYield * self::PENALTY_LOW_RATIO, 6);
                $penaltyApplied = 'low_ratio_penalty';
            }

            $yieldScore = max(0.0, min(1.0, $rawYield));

            // AC1: roi_score applies give_back penalty on top of yield.
            $roiScore   = round(max(0.0, min(1.0, $yieldScore * (1.0 - $giveBackRate * 0.5))), 4);

            $classification = $this->classify($yieldScore);
            $counts[$classification]++;

            // AC1: confidence, recommended_action, reasons.
            $confidence = match(true) {
                $acceptedSpecs >= self::HIGH_CONFIDENCE_SPECS => 'high',
                $acceptedSpecs >= self::MID_CONFIDENCE_SPECS  => 'medium',
                default                                        => 'low',
            };

            $reasons = [];
            if ($roiScore >= self::HIGH_THRESHOLD) {
                $reasons[] = 'high_roi';
            }
            if ($penaltyApplied === 'zero_delta_penalty') {
                $reasons[] = 'spec_bulk_no_delta';
            } elseif ($penaltyApplied === 'low_ratio_penalty') {
                $reasons[] = 'low_delta_ratio';
            }
            if ($giveBackRate > self::GIVE_BACK_DOWNRANK_FLOOR) {
                $reasons[] = 'high_give_back_rate';
            }
            if ($roiScore >= self::MID_THRESHOLD && $roiScore < self::HIGH_THRESHOLD && $reasons === []) {
                $reasons[] = 'moderate_delivery';
            }
            if ($roiScore < self::MID_THRESHOLD && $penaltyApplied === null && $giveBackRate <= self::GIVE_BACK_DOWNRANK_FLOOR) {
                $reasons[] = 'insufficient_delivery';
            }

            $recommendedAction = match(true) {
                $roiScore >= self::HIGH_THRESHOLD                                                              => 'invest',
                $penaltyApplied !== null || $giveBackRate > self::GIVE_BACK_DOWNRANK_FLOOR                     => 'deprioritize',
                $roiScore >= self::MID_THRESHOLD                                                               => 'watch',
                default                                                                                        => 'investigate',
            };

            $familyYields[] = [
                'family_id'         => $familyId,
                'yield_score'       => round($yieldScore, 4),
                'roi_score'         => $roiScore,
                'classification'    => $classification,
                'penalty_applied'   => $penaltyApplied,
                'confidence'        => $confidence,
                'recommended_action' => $recommendedAction,
                'reasons'           => $reasons,
            ];

            if ($classification === 'low_yield') {
                $lowYield[] = [
                    'family_id' => $familyId,
                    'reason'    => $penaltyApplied ?? 'insufficient_delivery',
                ];
            }
        }

        // Sort by roi_score descending (AC2: high-spec/low-delta families downranked via penalty+give_back).
        usort($familyYields, static fn ($a, $b) => $b['roi_score'] <=> $a['roi_score']);
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
