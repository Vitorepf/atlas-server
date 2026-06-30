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

    private const HYPE_SIGNAL_MIN   = 2;
    private const HYPE_EVIDENCE_CAP = 0.40;
    private const UNGROUNDED_CAP    = 0.30;
    private const PROMISING_MIN     = 0.70;
    private const ATLAS_FIT_FLOOR   = 0.50;
    private const RISK_CEILING      = 0.70;

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

        foreach ($rows as $row) {
            $id             = (string) ($row['id']    ?? '');
            $title          = (string) ($row['title'] ?? '');
            $evidence       = max(0.0, min(1.0, (float) ($row['evidence_strength'] ?? 0.0)));
            $hasCode        = (bool) ($row['has_code']      ?? false);
            $hasBenchmark   = (bool) ($row['has_benchmark'] ?? false);
            $hypeSignals    = array_values((array) ($row['hype_signals'] ?? []));
            $atlasfit       = max(0.0, min(1.0, (float) ($row['atlas_fit_score']             ?? 1.0)));
            $risk           = max(0.0, min(1.0, (float) ($row['implementation_risk']          ?? 0.0)));
            $providerDepSS  = (bool) ($row['provider_steady_state_dependency'] ?? false);
            $compoundImpact = max(0.0, min(1.0, (float) ($row['expected_compounding_impact'] ?? 0.0)));

            $entry = [
                'id'                          => $id,
                'title'                       => $title,
                'evidence_strength'           => $evidence,
                'atlas_fit_score'             => $atlasfit,
                'implementation_risk'         => $risk,
                'expected_compounding_impact' => $compoundImpact,
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

            // 6. Promising.
            if ($evidence >= self::PROMISING_MIN && ($hasCode || $hasBenchmark)) {
                $promising[] = array_merge($entry, ['has_code' => $hasCode, 'has_benchmark' => $hasBenchmark]);
                continue;
            }

            // 7. Exploratory.
            $exploratory[] = array_merge($entry, ['has_code' => $hasCode, 'has_benchmark' => $hasBenchmark]);
        }

        $leverageRank = $promising;
        usort($leverageRank, fn($a, $b): int => $b['expected_compounding_impact'] <=> $a['expected_compounding_impact']);

        return [
            'schema_version'               => self::SCHEMA,
            'promising'                    => $promising,
            'exploratory'                  => $exploratory,
            'hype_rejected'                => $hypeRejected,
            'ungrounded_rejected'          => $ungroundedRejected,
            'provider_dependent_rejected'  => $providerDependentRejected,
            'high_risk_rejected'           => $highRiskRejected,
            'no_atlas_fit_rejected'        => $noAtlasFitRejected,
            'promising_count'              => count($promising),
            'exploratory_count'            => count($exploratory),
            'hype_rejected_count'          => count($hypeRejected),
            'ungrounded_rejected_count'    => count($ungroundedRejected),
            'next_research_action'         => $this->nextAction($promising, $exploratory, $providerDependentRejected, $highRiskRejected),
            'leverage_rank'                => $leverageRank,
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
