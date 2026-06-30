<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure triage engine. Classifies locally-captured research frontier rows into buckets.
 *
 * Priority order (first match wins):
 *   hype_rejected               — hype_signals >= HYPE_SIGNAL_MIN AND evidence_strength < HYPE_EVIDENCE_CAP
 *   ungrounded_rejected         — no code AND no benchmark AND evidence_strength < UNGROUNDED_CAP
 *   provider_dependent_rejected — provider_steady_state_dependency = true
 *   high_risk_rejected          — implementation_risk >= RISK_CEILING
 *   no_atlas_fit_rejected       — atlas_fit_score < ATLAS_FIT_FLOOR
 *   promising                   — evidence_strength >= PROMISING_MIN AND (has_code OR has_benchmark)
 *   exploratory                 — all remaining rows
 *
 * Thresholds:
 *   HYPE_SIGNAL_MIN    = 2      (>=2 hype signals triggers hype check)
 *   HYPE_EVIDENCE_CAP  = 0.40   (below this AND has hype → hype_rejected)
 *   UNGROUNDED_CAP     = 0.30   (evidence < 0.30, no code, no benchmark → ungrounded)
 *   PROMISING_MIN      = 0.70
 *   ATLAS_FIT_FLOOR    = 0.50   (below this → no_atlas_fit_rejected)
 *   RISK_CEILING       = 0.70   (>= this → high_risk_rejected)
 *
 * Row fields (new fields default to backward-compatible pass-through values):
 *   atlas_fit_score               float [0-1]  default 1.0
 *   implementation_risk           float [0-1]  default 0.0
 *   provider_steady_state_dependency bool      default false
 *   expected_compounding_impact   float [0-1]  default 0.0
 *
 * Pure, deterministic, no providers, no I/O.
 */
final class AtlasExternalBrainLocalResearchFrontierTriageEngine
{
    public const SCHEMA = 'atlas.external_brain.local_research_frontier_triage_engine.v1';

    private const HYPE_SIGNAL_MIN    = 2;
    private const HYPE_EVIDENCE_CAP  = 0.40;
    private const UNGROUNDED_CAP     = 0.30;
    private const PROMISING_MIN      = 0.70;
    private const ATLAS_FIT_FLOOR    = 0.50;
    private const RISK_CEILING       = 0.70;
    private const SAFETY_RISK_CEILING = 0.70;

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function triage(array $facts): array
    {
        $rows = is_array($facts['frontier_rows'] ?? null) ? $facts['frontier_rows'] : [];

        $promising                 = [];
        $exploratory               = [];
        $hypeRejected              = [];
        $ungroundedRejected        = [];
        $providerDependentRejected = [];
        $highRiskRejected          = [];
        $noAtlasFitRejected        = [];
        $holdForReview             = [];

        foreach ($rows as $row) {
            $id                   = (string) ($row['id']    ?? '');
            $title                = (string) ($row['title'] ?? '');
            $evidence             = max(0.0, min(1.0, (float) ($row['evidence_strength'] ?? 0.0)));
            $hasCode              = (bool) ($row['has_code']      ?? false);
            $hasBenchmark         = (bool) ($row['has_benchmark'] ?? false);
            $hypeSignals          = array_values((array) ($row['hype_signals'] ?? []));
            $atlasfit             = max(0.0, min(1.0, (float) ($row['atlas_fit_score']              ?? 1.0)));
            $risk                 = max(0.0, min(1.0, (float) ($row['implementation_risk']           ?? 0.0)));
            $safetyRisk           = max(0.0, min(1.0, (float) ($row['safety_risk']                  ?? 0.0)));
            $providerDepSS        = (bool) ($row['provider_steady_state_dependency'] ?? false);
            $compoundImpact       = max(0.0, min(1.0, (float) ($row['expected_compounding_impact']  ?? 0.0)));
            $evidenceUnknown      = (bool) ($row['evidence_quality_unknown']          ?? false);
            $implTarget           = (string) ($row['implementation_target']           ?? '');
            $testTarget           = (string) ($row['test_target']                     ?? '');
            $sourceFamily         = (string) ($row['source_family']                   ?? '');
            $dedupKey             = (string) ($row['dedup_key'] ?? ($sourceFamily !== '' ? $sourceFamily.':'.$title : $title));

            // AC1: deterministic score fields derived from inputs.
            $evidenceQualityScore    = round(min(1.0, $evidence + ($hasCode ? 0.10 : 0.0) + ($hasBenchmark ? 0.10 : 0.0)), 4);
            $implementationRiskScore = round(min(1.0, $risk + ($providerDepSS ? 0.30 : 0.0)), 4);
            $providerDependencyScore = $providerDepSS ? 1.0 : 0.0;

            $entry = [
                'id'                          => $id,
                'title'                       => $title,
                'evidence_strength'           => $evidence,
                'atlas_fit_score'             => $atlasfit,
                'evidence_quality_score'      => $evidenceQualityScore,
                'implementation_risk'         => $risk,
                'implementation_risk_score'   => $implementationRiskScore,
                'provider_dependency_score'   => $providerDependencyScore,
                'expected_compounding_impact' => $compoundImpact,
                'source_family'               => $sourceFamily,
                'dedup_key'                   => $dedupKey,
            ];

            // 1. Hype-only rejection.
            if (count($hypeSignals) >= self::HYPE_SIGNAL_MIN && $evidence < self::HYPE_EVIDENCE_CAP) {
                $hypeRejected[] = array_merge($entry, [
                    'reason'            => 'hype_signals_with_low_evidence',
                    'hype_signal_count' => count($hypeSignals),
                ]);
                continue;
            }

            // 2. Ungrounded rejection.
            if (! $hasCode && ! $hasBenchmark && $evidence < self::UNGROUNDED_CAP) {
                $ungroundedRejected[] = array_merge($entry, ['reason' => 'no_code_no_benchmark_low_evidence']);
                continue;
            }

            // 3. Provider steady-state dependency.
            if ($providerDepSS) {
                $providerDependentRejected[] = array_merge($entry, ['reason' => 'provider_steady_state_dependency']);
                continue;
            }

            // 3.5. AC3: unknown evidence quality or high safety risk → hold for review.
            if ($evidenceUnknown) {
                $holdForReview[] = array_merge($entry, ['reason' => 'evidence_quality_unknown']);
                continue;
            }
            if ($safetyRisk >= self::SAFETY_RISK_CEILING) {
                $holdForReview[] = array_merge($entry, ['reason' => 'safety_risk_above_ceiling', 'safety_risk' => $safetyRisk]);
                continue;
            }

            // 4. High implementation risk.
            if ($risk >= self::RISK_CEILING) {
                $highRiskRejected[] = array_merge($entry, ['reason' => 'implementation_risk_above_ceiling']);
                continue;
            }

            // 5. No Atlas fit.
            if ($atlasfit < self::ATLAS_FIT_FLOOR) {
                $noAtlasFitRejected[] = array_merge($entry, ['reason' => 'atlas_fit_score_below_floor']);
                continue;
            }

            // 6. Promising — AC2: include task_seed_hints.
            if ($evidence >= self::PROMISING_MIN && ($hasCode || $hasBenchmark)) {
                $promising[] = array_merge($entry, [
                    'has_code'       => $hasCode,
                    'has_benchmark'  => $hasBenchmark,
                    'task_seed_hints' => [
                        'implementation_target' => $implTarget,
                        'test_target'           => $testTarget,
                        'expected_leverage'     => $compoundImpact,
                        'source_family'         => $sourceFamily,
                        'dedup_key'             => $dedupKey,
                    ],
                ]);
                continue;
            }

            // 7. Exploratory.
            $exploratory[] = array_merge($entry, ['has_code' => $hasCode, 'has_benchmark' => $hasBenchmark]);
        }

        // Duplicate family pressure: when 2+ promising rows share a source_family, the family
        // is over-represented — emit a warning and apply a rank penalty so quantity-of-source
        // never substitutes for diversity of evidence.
        $familyCounts = [];
        foreach ($promising as $row) {
            if ($row['source_family'] !== '') {
                $familyCounts[$row['source_family']] = ($familyCounts[$row['source_family']] ?? 0) + 1;
            }
        }
        $duplicateFamilyWarnings = [];
        foreach ($familyCounts as $family => $count) {
            if ($count > 1) {
                $duplicateFamilyWarnings[] = "source_family={$family}:count={$count}:duplicate_family_pressure_detected";
            }
        }

        $leverageRank = $promising;
        foreach ($leverageRank as &$row) {
            $familyCount = $familyCounts[$row['source_family']] ?? 1;
            $row['duplicate_family_pressure'] = $familyCount > 1;
            // Lower-rank score (display-only) penalised by family over-representation; the raw
            // expected_compounding_impact field is preserved unmodified.
            $row['rank_score'] = $familyCount > 1
                ? round($row['expected_compounding_impact'] * (1.0 / $familyCount), 4)
                : $row['expected_compounding_impact'];
        }
        unset($row);
        usort($leverageRank, fn ($a, $b): int => $b['rank_score'] <=> $a['rank_score']);

        // Source diversity summary across promising + exploratory rows.
        $diversityCounts = [];
        foreach (array_merge($promising, $exploratory) as $row) {
            $family = $row['source_family'] !== '' ? $row['source_family'] : 'unknown';
            $diversityCounts[$family] = ($diversityCounts[$family] ?? 0) + 1;
        }
        ksort($diversityCounts);
        $sourceDiversitySummary = [
            'unique_family_count' => count($diversityCounts),
            'counts_by_family'    => $diversityCounts,
        ];

        return [
            'schema_version'               => self::SCHEMA,
            'promising'                    => $promising,
            'exploratory'                  => $exploratory,
            'hold_for_review'              => $holdForReview,
            'hype_rejected'                => $hypeRejected,
            'ungrounded_rejected'          => $ungroundedRejected,
            'provider_dependent_rejected'  => $providerDependentRejected,
            'high_risk_rejected'           => $highRiskRejected,
            'no_atlas_fit_rejected'        => $noAtlasFitRejected,
            'promising_count'              => count($promising),
            'exploratory_count'            => count($exploratory),
            'hold_for_review_count'        => count($holdForReview),
            'hype_rejected_count'          => count($hypeRejected),
            'ungrounded_rejected_count'    => count($ungroundedRejected),
            'next_research_action'         => $this->nextAction($promising, $exploratory, $providerDependentRejected, $highRiskRejected),
            'leverage_rank'                => $leverageRank,
            'duplicate_family_warnings'    => $duplicateFamilyWarnings,
            'source_diversity_summary'     => $sourceDiversitySummary,
        ];
    }

    /** @param array<mixed> $promising @param array<mixed> $exploratory @param array<mixed> $providerDep @param array<mixed> $highRisk */
    private function nextAction(array $promising, array $exploratory, array $providerDep, array $highRisk): string
    {
        if ($promising !== []) {
            return 'harvest_top_promising';
        }
        if ($exploratory !== []) {
            return 'deepen_evidence_for_exploratory';
        }
        if ($providerDep !== []) {
            return 'identify_provider_free_alternatives';
        }
        if ($highRisk !== []) {
            return 'reduce_implementation_risk_before_adopting';
        }
        return 'expand_research_breadth';
    }
}
