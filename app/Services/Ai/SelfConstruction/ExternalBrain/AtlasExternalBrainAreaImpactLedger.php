<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure ledger. Aggregates task outcome samples by Atlas area to show which areas
 * actually gained capability, which only gained observability, and which are
 * accumulating low-value scaffolding.
 *
 * INVARIANTS (AC2):
 *  - capability_gain counts ONLY value_class='real_capability' WITH integration_evidence=true.
 *    Scaffolding, observability, consolidation, and unknown never inflate capability gain.
 *  - volume_without_evidence=true when an area has ≥3 total tasks but capability_gain=0.
 *
 * next_structural_lever per area (first match wins):
 *   review_scaffolding — scaffolding_risk > capability_gain AND scaffolding_risk ≥ 2
 *   investigate        — volume_without_evidence (volume rising, no proven capability)
 *   invest             — capability_gain ≥ 3
 *   observe            — observability_gain dominant (> capability_gain + scaffolding_risk)
 *   consolidate        — consolidation_count ≥ 2 AND consolidation_count > capability_gain
 *   monitor            — default
 *
 * maturity_band:
 *   mature     — capability_gain ≥ 3 AND integration_evidence
 *   developing — capability_gain ≥ 1 AND integration_evidence
 *   stagnant   — volume_without_evidence
 *   emerging   — default
 *
 * risk_level:
 *   high   — scaffolding_risk > capability_gain AND scaffolding_risk ≥ 2
 *   medium — scaffolding_risk > 0
 *   low    — scaffolding_risk = 0
 *
 * owner_signal:
 *   proven   — integration_evidence AND capability_gain > 0
 *   claimed  — integration_evidence AND capability_gain = 0
 *   unowned  — no integration_evidence
 *
 * Additional impact dimensions (AC2), accumulated per area from optional sample fields —
 * absent on a sample contributes 0.0/0, so existing callers that never set them see no change:
 *   risk_reduction     — float, sums into compound_impact_score (real risk mitigated)
 *   autonomy_gain      — float, sums into compound_impact_score (less human/operator dependency)
 *   downstream_unlocks — int, weighted 2x into compound_impact_score (this area's work unblocked
 *                        other areas — a downstream multiplier, not raw task/file volume)
 *   proof_strength     — float 0..1 (default 1.0), the min across an area's samples; a stale
 *                        evidence_age_days downgrades maturity_band/owner_signal by one step
 *                        (mature→developing, developing→emerging, proven→claimed) — a proof that
 *                        has gone stale can never keep asserting full maturity/ownership.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainAreaImpactLedger
{
    public const SCHEMA = 'atlas.external_brain.area_impact_ledger.v1';

    public const VALUE_REAL_CAPABILITY = 'real_capability';
    public const VALUE_OBSERVABILITY   = 'observability';
    public const VALUE_CONSOLIDATION   = 'consolidation';
    public const VALUE_SCAFFOLDING     = 'scaffolding';
    public const VALUE_UNKNOWN         = 'unknown';

    public const ACTION_INVEST             = 'invest';
    public const ACTION_REVIEW_SCAFFOLDING = 'review_scaffolding';
    public const ACTION_INVESTIGATE        = 'investigate';
    public const ACTION_OBSERVE            = 'observe';
    public const ACTION_CONSOLIDATE        = 'consolidate';
    public const ACTION_MONITOR            = 'monitor';

    private const VOLUME_WITHOUT_EVIDENCE_THRESHOLD = 3;
    private const INVEST_CAPABILITY_THRESHOLD       = 3;
    private const SCAFFOLDING_RISK_MIN              = 2;
    private const CONSOLIDATION_MIN                 = 2;
    private const STALE_EVIDENCE_AGE_DAYS           = 30;

    /**
     * @param  array{samples?: list<array<string,mixed>>}  $input
     * @return array<string,mixed>
     */
    public function aggregate(array $input): array
    {
        $samples = is_array($input['samples'] ?? null) ? $input['samples'] : [];

        $buckets = [];

        foreach ($samples as $sample) {
            $area       = (string) ($sample['area']                 ?? 'unknown_area');
            $valueClass = (string) ($sample['value_class']          ?? self::VALUE_UNKNOWN);
            $integrated = (bool)   ($sample['integration_evidence'] ?? false);
            $count      = max(1,   (int) ($sample['task_count']     ?? 1));

            if (! isset($buckets[$area])) {
                $buckets[$area] = [
                    'capability_gain'        => 0,
                    'observability_gain'     => 0,
                    'scaffolding_risk'       => 0,
                    'consolidation_count'    => 0,
                    'unknown_count'          => 0,
                    'total_tasks'            => 0,
                    'integration_evidence'   => false,
                    'max_evidence_age_days'  => null,
                    'risk_reduction'         => 0.0,
                    'autonomy_gain'          => 0.0,
                    'downstream_unlocks'     => 0,
                    'min_proof_strength'     => 1.0,
                ];
            }

            $buckets[$area]['total_tasks'] += $count;

            if ($integrated) {
                $buckets[$area]['integration_evidence'] = true;
            }

            $buckets[$area]['risk_reduction'] += max(0.0, (float) ($sample['risk_reduction'] ?? 0.0));
            $buckets[$area]['autonomy_gain'] += max(0.0, (float) ($sample['autonomy_gain'] ?? 0.0));
            $buckets[$area]['downstream_unlocks'] += max(0, (int) ($sample['downstream_unlocks'] ?? 0));
            $proofStrength = max(0.0, min(1.0, (float) ($sample['proof_strength'] ?? 1.0)));
            $buckets[$area]['min_proof_strength'] = min($buckets[$area]['min_proof_strength'], $proofStrength);

            if (isset($sample['evidence_age_days'])) {
                $age = max(0, (int) $sample['evidence_age_days']);
                $buckets[$area]['max_evidence_age_days'] = $buckets[$area]['max_evidence_age_days'] === null
                    ? $age
                    : max($buckets[$area]['max_evidence_age_days'], $age);
            }

            // AC2: only real_capability WITH integration evidence → capability_gain
            match ($valueClass) {
                self::VALUE_REAL_CAPABILITY => $integrated
                    ? ($buckets[$area]['capability_gain'] += $count)
                    : ($buckets[$area]['observability_gain'] += $count),
                self::VALUE_OBSERVABILITY => $buckets[$area]['observability_gain']  += $count,
                self::VALUE_SCAFFOLDING   => $buckets[$area]['scaffolding_risk']    += $count,
                self::VALUE_CONSOLIDATION => $buckets[$area]['consolidation_count'] += $count,
                default                   => $buckets[$area]['unknown_count']       += $count,
            };
        }

        $areas = [];
        foreach ($buckets as $area => $b) {
            $volumeWithoutEvidence = $b['total_tasks'] >= self::VOLUME_WITHOUT_EVIDENCE_THRESHOLD
                && $b['capability_gain'] === 0;

            $backlogPressure = $b['total_tasks'] > 0
                ? round(min(1.0, ($b['scaffolding_risk'] + $b['unknown_count']) / $b['total_tasks']), 4)
                : 0.0;

            // Compound score: capability weighted highest, observability neutral, scaffolding
            // penalised, plus real risk reduction, autonomy gain, and downstream unlocks (weighted
            // 2x — unlocking OTHER areas' work is a multiplier, not raw task/file volume).
            $compoundImpactScore = ($b['capability_gain'] * 3) + $b['observability_gain'] - ($b['scaffolding_risk'] * 2)
                + $b['risk_reduction'] + $b['autonomy_gain'] + ($b['downstream_unlocks'] * 2);

            $evidenceFreshness = $this->computeEvidenceFreshness($b['max_evidence_age_days']);
            $maturityBand = $this->computeMaturityBand($b, $volumeWithoutEvidence);
            $ownerSignal = $this->computeOwnerSignal($b);

            // AC4: a stale proof can never keep asserting full maturity/ownership — downgrade
            // one step regardless of how strong the underlying counts looked.
            if ($evidenceFreshness === 'stale') {
                $maturityBand = match ($maturityBand) {
                    'mature' => 'developing',
                    'developing' => 'emerging',
                    default => $maturityBand,
                };
                $ownerSignal = $ownerSignal === 'proven' ? 'claimed' : $ownerSignal;
            }

            $areas[$area] = [
                'area'                    => $area,
                'capability_gain'         => $b['capability_gain'],
                'observability_gain'      => $b['observability_gain'],
                'scaffolding_risk'        => $b['scaffolding_risk'],
                'consolidation_count'     => $b['consolidation_count'],
                'total_tasks'             => $b['total_tasks'],
                'integration_evidence'    => $b['integration_evidence'],
                'volume_without_evidence' => $volumeWithoutEvidence,
                'backlog_pressure'        => $backlogPressure,
                'risk_reduction'          => round($b['risk_reduction'], 4),
                'autonomy_gain'           => round($b['autonomy_gain'], 4),
                'downstream_unlocks'      => $b['downstream_unlocks'],
                'proof_strength'          => round($b['min_proof_strength'], 4),
                'compound_impact_score'   => $compoundImpactScore,
                'impact_rank'             => 0,
                'maturity_band'           => $maturityBand,
                'risk_level'              => $this->computeRiskLevel($b),
                'owner_signal'            => $ownerSignal,
                'next_structural_lever'   => $this->computeLever($b, $volumeWithoutEvidence),
                'evidence_age_days'       => $b['max_evidence_age_days'],
                'evidence_freshness'      => $evidenceFreshness,
            ];
        }

        // Sort by compound_impact_score descending; area name ascending as tiebreaker for determinism.
        uasort($areas, static function (array $x, array $y): int {
            return $y['compound_impact_score'] <=> $x['compound_impact_score']
                ?: strcmp($x['area'], $y['area']);
        });

        $rank = 1;
        foreach ($areas as &$areaEntry) {
            $areaEntry['impact_rank'] = $rank++;
        }
        unset($areaEntry);

        // Top-ranked area with a non-trivial lever is the next leverage candidate.
        $nextLeverageCandidate = null;
        foreach ($areas as $areaName => $areaEntry) {
            if ($areaEntry['next_structural_lever'] !== self::ACTION_MONITOR) {
                $nextLeverageCandidate = $areaName;
                break;
            }
        }
        $nextLeverageCandidate ??= array_key_first($areas);

        // One deterministic knowledge-sync entry per area, mirroring the per-area facts already
        // computed above — a direct feed for domain-map maturity/ownership sync consumers.
        $domainMapUpdates = [];
        foreach ($areas as $areaEntry) {
            $domainMapUpdates[] = [
                'area'                  => $areaEntry['area'],
                'maturity_band'         => $areaEntry['maturity_band'],
                'owner_signal'          => $areaEntry['owner_signal'],
                'evidence_freshness'    => $areaEntry['evidence_freshness'],
                'next_structural_lever' => $areaEntry['next_structural_lever'],
            ];
        }

        return [
            'schema'                  => self::SCHEMA,
            'areas'                   => $areas,
            'area_count'              => count($areas),
            'sample_count'            => count($samples),
            'next_leverage_candidate' => $nextLeverageCandidate,
            'domain_map_updates'      => $domainMapUpdates,
        ];
    }

    private function computeLever(array $b, bool $volumeWithoutEvidence): string
    {
        if ($b['scaffolding_risk'] > $b['capability_gain'] && $b['scaffolding_risk'] >= self::SCAFFOLDING_RISK_MIN) {
            return self::ACTION_REVIEW_SCAFFOLDING;
        }
        if ($volumeWithoutEvidence) {
            return self::ACTION_INVESTIGATE;
        }
        if ($b['capability_gain'] >= self::INVEST_CAPABILITY_THRESHOLD) {
            return self::ACTION_INVEST;
        }
        if ($b['observability_gain'] > $b['capability_gain'] + $b['scaffolding_risk']) {
            return self::ACTION_OBSERVE;
        }
        if ($b['consolidation_count'] >= self::CONSOLIDATION_MIN && $b['consolidation_count'] > $b['capability_gain']) {
            return self::ACTION_CONSOLIDATE;
        }

        return self::ACTION_MONITOR;
    }

    private function computeMaturityBand(array $b, bool $volumeWithoutEvidence): string
    {
        if ($b['capability_gain'] >= self::INVEST_CAPABILITY_THRESHOLD && $b['integration_evidence']) {
            return 'mature';
        }
        if ($b['capability_gain'] >= 1 && $b['integration_evidence']) {
            return 'developing';
        }
        if ($volumeWithoutEvidence) {
            return 'stagnant';
        }

        return 'emerging';
    }

    private function computeRiskLevel(array $b): string
    {
        if ($b['scaffolding_risk'] > $b['capability_gain'] && $b['scaffolding_risk'] >= self::SCAFFOLDING_RISK_MIN) {
            return 'high';
        }
        if ($b['scaffolding_risk'] > 0) {
            return 'medium';
        }

        return 'low';
    }

    private function computeEvidenceFreshness(?int $maxEvidenceAgeDays): string
    {
        if ($maxEvidenceAgeDays === null) {
            return 'unknown';
        }

        return $maxEvidenceAgeDays > self::STALE_EVIDENCE_AGE_DAYS ? 'stale' : 'fresh';
    }

    private function computeOwnerSignal(array $b): string
    {
        if (! $b['integration_evidence']) {
            return 'unowned';
        }

        return $b['capability_gain'] > 0 ? 'proven' : 'claimed';
    }
}
