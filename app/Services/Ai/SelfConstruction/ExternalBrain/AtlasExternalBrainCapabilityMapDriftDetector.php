<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure detector: compares the brain's domain capability map against queued areas
 * and evidence, surfaces disagreements as ranked drift findings.
 *
 * Drift types:
 *   contradictory  → map state=integrated but has_completion_evidence=false (map actively lies)
 *   missing        → queued area not in the map at all (originators plan from void)
 *   stale          → map entry last_updated_age_days > 30 (knowledge may be outdated)
 *
 * Impact ranking: high (contradictory, missing) > medium (stale) > low
 * Within the same impact tier, sorted alphabetically by area_id.
 */
final class AtlasExternalBrainCapabilityMapDriftDetector
{
    public const SCHEMA = 'atlas.external_brain.capability_map_drift_detector.v1';

    public const DRIFT_STALE = 'stale';

    public const DRIFT_MISSING = 'missing';

    public const DRIFT_CONTRADICTORY = 'contradictory';

    public const STALE_AGE_THRESHOLD_DAYS = 30;

    private const IMPACT_ORDER = ['high' => 0, 'medium' => 1, 'low' => 2];

    /** @var array<string,list<string>> */
    private const EVIDENCE_BY_DRIFT = [
        self::DRIFT_CONTRADICTORY => ['completion_proof', 'integration_test_result'],
        self::DRIFT_MISSING => ['area_discovery_scan', 'capability_mapping'],
        self::DRIFT_STALE => ['area_revalidation', 'fresh_evidence_scan'],
    ];

    /**
     * @param  array<string,mixed>  $input  map_entries, queued_areas
     * @return array{schema_version:string, has_drift:bool, findings:list<array<string,mixed>>, total_findings:int}
     */
    public function detect(array $input): array
    {
        $mapEntries = is_array($input['map_entries'] ?? null) ? $input['map_entries'] : [];
        $queuedAreas = array_values(array_filter(
            array_map('strval', (array) ($input['queued_areas'] ?? [])),
            static fn (string $a): bool => $a !== '',
        ));

        $findings = [];
        $mappedIds = [];

        foreach ($mapEntries as $entry) {
            $areaId = (string) ($entry['area_id'] ?? '');
            $state = (string) ($entry['state'] ?? '');
            $ageDays = max(0, (int) ($entry['last_updated_age_days'] ?? 0));
            $hasEvidence = (bool) ($entry['has_completion_evidence'] ?? true);

            $mappedIds[] = $areaId;

            if ($state === 'integrated' && ! $hasEvidence) {
                $findings[] = $this->finding($areaId, self::DRIFT_CONTRADICTORY, 'high');
            } elseif ($ageDays > self::STALE_AGE_THRESHOLD_DAYS) {
                $findings[] = $this->finding($areaId, self::DRIFT_STALE, 'medium');
            }
        }

        foreach ($queuedAreas as $area) {
            if (! in_array($area, $mappedIds, true)) {
                $findings[] = $this->finding($area, self::DRIFT_MISSING, 'high');
            }
        }

        usort($findings, static function (array $a, array $b): int {
            $ia = self::IMPACT_ORDER[$a['impact_level']] ?? 99;
            $ib = self::IMPACT_ORDER[$b['impact_level']] ?? 99;

            return $ia !== $ib ? $ia <=> $ib : strcmp($a['area_id'], $b['area_id']);
        });

        return [
            'schema_version' => self::SCHEMA,
            'has_drift' => $findings !== [],
            'findings' => array_values($findings),
            'total_findings' => count($findings),
        ];
    }

    /** @return array<string,mixed> */
    private function finding(string $areaId, string $driftType, string $impactLevel): array
    {
        return [
            'area_id' => $areaId,
            'drift_type' => $driftType,
            'impact_level' => $impactLevel,
            'evidence_needed' => self::EVIDENCE_BY_DRIFT[$driftType],
        ];
    }
}
