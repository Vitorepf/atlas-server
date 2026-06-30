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
 *    Scaffolding, observability, and consolidation never count as capability gain.
 *  - volume_without_evidence=true when an area has ≥3 total tasks but capability_gain=0,
 *    flagging work that isn't converting to proven capability.
 *
 * next_action per area (first match wins):
 *   review_scaffolding — scaffolding_risk > capability_gain AND scaffolding_risk ≥ 2
 *   investigate        — volume_without_evidence (volume rising, no proven capability)
 *   invest             — capability_gain ≥ 3
 *   observe            — observability_gain dominant (> capability_gain + scaffolding_risk)
 *   monitor            — default
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

    public const ACTION_INVEST              = 'invest';
    public const ACTION_REVIEW_SCAFFOLDING  = 'review_scaffolding';
    public const ACTION_INVESTIGATE         = 'investigate';
    public const ACTION_OBSERVE             = 'observe';
    public const ACTION_MONITOR             = 'monitor';

    private const VOLUME_WITHOUT_EVIDENCE_THRESHOLD = 3;
    private const INVEST_CAPABILITY_THRESHOLD       = 3;
    private const SCAFFOLDING_RISK_MIN              = 2;

    /**
     * @param  array{samples?: list<array<string,mixed>>}  $input
     * @return array{schema:string, areas:array<string,mixed>, area_count:int, sample_count:int}
     */
    public function aggregate(array $input): array
    {
        $samples = is_array($input['samples'] ?? null) ? $input['samples'] : [];

        /** @var array<string,array{capability_gain:int,observability_gain:int,scaffolding_risk:int,consolidation_count:int,unknown_count:int,total_tasks:int,has_integration_evidence:bool}> $buckets */
        $buckets = [];

        foreach ($samples as $sample) {
            $area       = (string) ($sample['area']                 ?? 'unknown_area');
            $valueClass = (string) ($sample['value_class']          ?? self::VALUE_UNKNOWN);
            $integrated = (bool)   ($sample['integration_evidence'] ?? false);
            $count      = max(1,   (int) ($sample['task_count']     ?? 1));

            if (! isset($buckets[$area])) {
                $buckets[$area] = [
                    'capability_gain'       => 0,
                    'observability_gain'    => 0,
                    'scaffolding_risk'      => 0,
                    'consolidation_count'   => 0,
                    'unknown_count'         => 0,
                    'total_tasks'           => 0,
                    'has_integration_evidence' => false,
                ];
            }

            $buckets[$area]['total_tasks'] += $count;

            if ($integrated) {
                $buckets[$area]['has_integration_evidence'] = true;
            }

            // AC2: only real_capability WITH integration evidence → capability_gain
            match ($valueClass) {
                self::VALUE_REAL_CAPABILITY => $integrated
                    ? ($buckets[$area]['capability_gain'] += $count)
                    : ($buckets[$area]['observability_gain'] += $count), // no integration = unproven
                self::VALUE_OBSERVABILITY => $buckets[$area]['observability_gain'] += $count,
                self::VALUE_SCAFFOLDING   => $buckets[$area]['scaffolding_risk']   += $count,
                self::VALUE_CONSOLIDATION => $buckets[$area]['consolidation_count'] += $count,
                default                   => $buckets[$area]['unknown_count']       += $count,
            };
        }

        $areas = [];
        foreach ($buckets as $area => $b) {
            $volumeWithoutEvidence = $b['total_tasks'] >= self::VOLUME_WITHOUT_EVIDENCE_THRESHOLD
                && $b['capability_gain'] === 0;

            $nextAction = $this->computeNextAction($b, $volumeWithoutEvidence);

            $areas[$area] = [
                'area'                    => $area,
                'capability_gain'         => $b['capability_gain'],
                'observability_gain'      => $b['observability_gain'],
                'scaffolding_risk'        => $b['scaffolding_risk'],
                'consolidation_count'     => $b['consolidation_count'],
                'total_tasks'             => $b['total_tasks'],
                'has_integration_evidence' => $b['has_integration_evidence'],
                'volume_without_evidence' => $volumeWithoutEvidence,
                'next_action'             => $nextAction,
            ];
        }

        ksort($areas);

        return [
            'schema'       => self::SCHEMA,
            'areas'        => $areas,
            'area_count'   => count($areas),
            'sample_count' => count($samples),
        ];
    }

    private function computeNextAction(array $b, bool $volumeWithoutEvidence): string
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

        return self::ACTION_MONITOR;
    }
}
