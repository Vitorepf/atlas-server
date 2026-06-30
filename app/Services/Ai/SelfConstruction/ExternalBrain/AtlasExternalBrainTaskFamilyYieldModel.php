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

    private const SPEC_BULK_THRESHOLD      = 4;
    private const DELTA_RATIO_THRESHOLD   = 0.20;
    private const PENALTY_ZERO_DELTA      = 0.10;
    private const PENALTY_LOW_RATIO       = 0.50;
    private const HIGH_THRESHOLD          = 0.70;
    private const MID_THRESHOLD           = 0.30;
    private const GIVE_BACK_DOWNRANK_FLOOR = 0.30;
    private const HIGH_CONFIDENCE_SPECS   = 5;
    private const MID_CONFIDENCE_SPECS    = 2;
    // AC1/AC2: outcome-count formula path
    private const MIN_SAMPLE_FOR_EVIDENCE = 3;
    private const POISON_PENALTY_FACTOR   = 0.30;
    private const HIGH_POISON_RATE        = 0.50;

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function model(array $facts): array
    {
        $rawFamilies = is_array($facts['families'] ?? null) ? $facts['families'] : [];

        $familyYields   = [];
        $lowYield       = [];
        $counts         = ['high_yield' => 0, 'moderate_yield' => 0, 'low_yield' => 0, 'insufficient_evidence' => 0];

        foreach ($rawFamilies as $raw) {
            $familyId     = (string) ($raw['family_id'] ?? '');
            $giveBackRate = max(0.0, min(1.0, (float) ($raw['give_back_rate'] ?? 0.0)));

            // AC1 (rank02): outcome-count formula path when any count field is supplied.
            $hasOutcomeCounts = array_key_exists('success_count', $raw)
                || array_key_exists('give_back_count', $raw)
                || array_key_exists('poison_count', $raw)
                || array_key_exists('quarantine_count', $raw);

            if ($hasOutcomeCounts) {
                $successCount    = max(0, (int) ($raw['success_count']    ?? 0));
                $giveBackCount   = max(0, (int) ($raw['give_back_count']  ?? 0));
                $poisonCount     = max(0, (int) ($raw['poison_count']     ?? 0));
                $quarantineCount = max(0, (int) ($raw['quarantine_count'] ?? 0));
                $totalAttempted  = $successCount + $giveBackCount + $poisonCount + $quarantineCount;

                // AC2 (rank02): low-sample families cannot outrank proven ones.
                if ($totalAttempted < self::MIN_SAMPLE_FOR_EVIDENCE) {
                    $yieldScore      = 0.0;
                    $roiScore        = 0.0;
                    $penaltyApplied  = null;
                    $poisonRate      = 0.0;
                    $classification  = 'insufficient_evidence';
                    $confidence      = 'low';
                    $reasons         = ['insufficient_sample'];
                    $recommendedAction = 'watch';
                } else {
                    $greenRate   = $successCount / $totalAttempted;
                    $poisonRate  = ($giveBackCount + $poisonCount + $quarantineCount) / $totalAttempted;
                    $yieldScore  = max(0.0, min(1.0, $greenRate - $poisonRate * self::POISON_PENALTY_FACTOR));
                    $roiScore    = round(max(0.0, min(1.0, $yieldScore * (1.0 - $giveBackRate * 0.5))), 4);
                    $penaltyApplied = $poisonRate >= self::HIGH_POISON_RATE ? 'high_poison_rate_penalty' : null;
                    $classification = $this->classify($yieldScore);
                    $confidence  = 'high'; // sufficient evidence
                    $reasons     = [];
                    if ($poisonRate >= self::HIGH_POISON_RATE) {
                        $reasons[] = 'high_poison_rate';
                    }
                    if ($giveBackRate > self::GIVE_BACK_DOWNRANK_FLOOR) {
                        $reasons[] = 'high_give_back_rate';
                    }
                    $recommendedAction = match(true) {
                        $classification === 'high_yield' && $poisonRate < self::HIGH_POISON_RATE => 'promote',
                        $poisonRate >= self::HIGH_POISON_RATE                                    => 'quarantine_pattern',
                        $classification === 'moderate_yield'                                     => 'watch',
                        default                                                                  => 'deprioritize',
                    };
                }

                $counts[$classification]++;

                $familyYields[] = [
                    'family_id'              => $familyId,
                    'yield_score'            => round($yieldScore, 4),
                    'roi_score'              => $roiScore,
                    'classification'         => $classification,
                    'penalty_applied'        => $penaltyApplied,
                    'confidence'             => $confidence,
                    'recommended_action'     => $recommendedAction,
                    'recommended_family_action' => $recommendedAction,
                    'reasons'                => $reasons,
                ];

                if (in_array($classification, ['low_yield', 'insufficient_evidence'], true)) {
                    $lowYield[] = [
                        'family_id' => $familyId,
                        'reason'    => $penaltyApplied ?? ($classification === 'insufficient_evidence' ? 'insufficient_sample' : 'insufficient_delivery'),
                    ];
                }

                continue;
            }

            // ── Legacy path (original formula, backward-compat) ─────────────────
            $acceptedSpecs = max(0, (int) ($raw['accepted_specs']              ?? 0));
            $deltas        = max(0, (int) ($raw['resolved_capability_deltas']  ?? 0));
            $unlocks       = max(0, (int) ($raw['architecture_unlocks']        ?? 0));
            $wiring        = max(0, (int) ($raw['verified_wiring_changes']     ?? 0));

            $deliveryScore = $deltas + $unlocks + $wiring;
            $rawYield      = round($deliveryScore / ($acceptedSpecs + 1), 6);

            $penaltyApplied = null;
            if ($acceptedSpecs >= self::SPEC_BULK_THRESHOLD && $deltas === 0) {
                $rawYield       = round($rawYield * self::PENALTY_ZERO_DELTA, 6);
                $penaltyApplied = 'zero_delta_penalty';
            } elseif ($acceptedSpecs > 0 && ($deltas / $acceptedSpecs) < self::DELTA_RATIO_THRESHOLD) {
                $rawYield       = round($rawYield * self::PENALTY_LOW_RATIO, 6);
                $penaltyApplied = 'low_ratio_penalty';
            }

            $yieldScore = max(0.0, min(1.0, $rawYield));
            $roiScore   = round(max(0.0, min(1.0, $yieldScore * (1.0 - $giveBackRate * 0.5))), 4);

            $classification = $this->classify($yieldScore);
            $counts[$classification]++;

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
                $roiScore >= self::HIGH_THRESHOLD                                                 => 'invest',
                $penaltyApplied !== null || $giveBackRate > self::GIVE_BACK_DOWNRANK_FLOOR        => 'deprioritize',
                $roiScore >= self::MID_THRESHOLD                                                  => 'watch',
                default                                                                           => 'investigate',
            };

            $familyYields[] = [
                'family_id'              => $familyId,
                'yield_score'            => round($yieldScore, 4),
                'roi_score'              => $roiScore,
                'classification'         => $classification,
                'penalty_applied'        => $penaltyApplied,
                'confidence'             => $confidence,
                'recommended_action'     => $recommendedAction,
                'recommended_family_action' => $recommendedAction,
                'reasons'                => $reasons,
            ];

            if ($classification === 'low_yield') {
                $lowYield[] = [
                    'family_id' => $familyId,
                    'reason'    => $penaltyApplied ?? 'insufficient_delivery',
                ];
            }
        }

        // Sort: insufficient_evidence always after proven families, then by roi_score desc.
        usort($familyYields, static function (array $a, array $b): int {
            $aInsuf = $a['classification'] === 'insufficient_evidence';
            $bInsuf = $b['classification'] === 'insufficient_evidence';
            if ($aInsuf !== $bInsuf) {
                return $aInsuf ? 1 : -1;
            }
            return $b['roi_score'] <=> $a['roi_score'];
        });
        $rankedFamilies = array_column($familyYields, 'family_id');

        $recommendedFamilyActions = array_map(static fn ($f) => [
            'family_id' => $f['family_id'],
            'action'    => $f['recommended_family_action'],
        ], $familyYields);

        return [
            'schema_version'            => self::SCHEMA,
            'family_yields'             => $familyYields,
            'ranked_families'           => $rankedFamilies,
            'low_yield_families'        => $lowYield,
            'recommended_family_actions' => $recommendedFamilyActions,
            'summary'                   => [
                'total_families'       => count($rawFamilies),
                'high_yield'           => $counts['high_yield'],
                'moderate_yield'       => $counts['moderate_yield'],
                'low_yield'            => $counts['low_yield'],
                'insufficient_evidence' => $counts['insufficient_evidence'],
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
