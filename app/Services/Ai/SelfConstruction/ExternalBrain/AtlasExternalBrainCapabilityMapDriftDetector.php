<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure detector: compares the brain's domain capability map against queued areas
 * and evidence, surfaces disagreements as ranked drift findings.
 *
 * Drift types:
 *   contradictory                → map state=integrated but has_completion_evidence=false
 *   missing                      → queued area not in the map at all
 *   stale                        → map entry last_updated_age_days > 30
 *   missing_owner                → map entry has no (or empty) owner field
 *   missing_maturity_band        → map entry has no (or empty) maturity_band field
 *   stale_owner_evidence         → owner_evidence_age_days > STALE_AGE_THRESHOLD_DAYS
 *   maturity_regression          → previous_maturity_band was higher AND no follow-up task exists
 *   retired_blocked_queue_conflict → queued work targets an area whose map state is retired or blocked
 *
 * Impact ranking: high (contradictory, missing, retired_blocked_queue_conflict) > medium (all others) > low
 * Within the same impact tier, sorted alphabetically by area_id.
 */
final class AtlasExternalBrainCapabilityMapDriftDetector
{
    public const SCHEMA = 'atlas.external_brain.capability_map_drift_detector.v1';

    public const DRIFT_STALE                      = 'stale';
    public const DRIFT_MISSING                     = 'missing';
    public const DRIFT_CONTRADICTORY               = 'contradictory';
    public const DRIFT_MISSING_OWNER               = 'missing_owner';
    public const DRIFT_MISSING_MATURITY_BAND       = 'missing_maturity_band';
    public const DRIFT_STALE_OWNER_EVIDENCE        = 'stale_owner_evidence';
    public const DRIFT_MATURITY_REGRESSION         = 'maturity_regression';
    public const DRIFT_RETIRED_BLOCKED_QUEUE       = 'retired_blocked_queue_conflict';
    public const DRIFT_MISSING_NEXT_LEVERAGE       = 'missing_next_leverage';

    public const STALE_AGE_THRESHOLD_DAYS = 30;

    /** Maturity bands that imply "done" — contradicted by a recent run of bad outcomes. */
    private const ADVANCED_MATURITY_BANDS = ['advanced', 'autonomous'];

    private const BAD_OUTCOME_RESULTS = ['failure', 'give_back', 'poison'];

    /** Minimum bad-outcome count + majority-over-success required to flag a contradiction. */
    private const OUTCOME_CONTRADICTION_MIN_BAD_COUNT = 2;

    private const CONFIDENCE_BOOST = ['low' => 'medium', 'medium' => 'high', 'high' => 'high'];

    private const IMPACT_ORDER = ['high' => 0, 'medium' => 1, 'low' => 2];

    private const MATURITY_BAND_ORDER = [
        'bootstrapping' => 0,
        'emerging'      => 1,
        'functional'    => 2,
        'advanced'      => 3,
        'autonomous'    => 4,
    ];

    /** @var array<string,list<string>> */
    private const EVIDENCE_BY_DRIFT = [
        self::DRIFT_CONTRADICTORY          => ['completion_proof', 'integration_test_result'],
        self::DRIFT_MISSING                => ['area_discovery_scan', 'capability_mapping'],
        self::DRIFT_STALE                  => ['area_revalidation', 'fresh_evidence_scan'],
        self::DRIFT_MISSING_OWNER          => ['owner_assignment', 'domain_mapping'],
        self::DRIFT_MISSING_MATURITY_BAND  => ['maturity_assessment', 'capability_evaluation'],
        self::DRIFT_STALE_OWNER_EVIDENCE   => ['owner_revalidation', 'fresh_ownership_proof'],
        self::DRIFT_MATURITY_REGRESSION    => ['regression_root_cause', 'follow_up_task_ref'],
        self::DRIFT_RETIRED_BLOCKED_QUEUE  => ['queue_redirect', 'area_reactivation_gate'],
        self::DRIFT_MISSING_NEXT_LEVERAGE  => ['leverage_assessment', 'next_opportunity_scan'],
    ];

    /** @var array<string,string> drift_type → repair_action (queue conflict resolved per-state below). */
    private const REPAIR_ACTION_BY_DRIFT = [
        self::DRIFT_CONTRADICTORY          => 'request_completion_proof_or_demote_state',
        self::DRIFT_MISSING                => 'add_area_to_capability_map',
        self::DRIFT_STALE                  => 'revalidate_area_freshness',
        self::DRIFT_MISSING_OWNER          => 'assign_owner',
        self::DRIFT_MISSING_MATURITY_BAND  => 'assess_maturity_band',
        self::DRIFT_STALE_OWNER_EVIDENCE   => 'revalidate_ownership',
        self::DRIFT_MATURITY_REGRESSION    => 'root_cause_regression_and_link_follow_up_task',
        self::DRIFT_MISSING_NEXT_LEVERAGE  => 'run_leverage_assessment',
    ];

    /** @var array<string,string> drift_type → Task Fabric handling advice. */
    private const TASK_FABRIC_ADVICE_BY_DRIFT = [
        self::DRIFT_CONTRADICTORY          => 'treat_claims_as_unverified_until_repaired',
        self::DRIFT_MISSING                => 'do_not_enqueue_until_area_is_mapped',
        self::DRIFT_RETIRED_BLOCKED_QUEUE  => 'do_not_enqueue_until_reactivation_gate_passes',
        self::DRIFT_STALE                  => 'proceed_with_caution_pending_revalidation',
        self::DRIFT_MISSING_OWNER          => 'proceed_with_caution_pending_repair',
        self::DRIFT_MISSING_MATURITY_BAND  => 'proceed_with_caution_pending_repair',
        self::DRIFT_STALE_OWNER_EVIDENCE   => 'proceed_with_caution_pending_repair',
        self::DRIFT_MATURITY_REGRESSION    => 'proceed_with_caution_pending_repair',
        self::DRIFT_MISSING_NEXT_LEVERAGE  => 'proceed_with_caution_pending_repair',
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
        $outcomes = is_array($input['outcomes'] ?? null) ? $input['outcomes'] : [];

        // Group outcomes by area_id for O(1) lookup below.
        $outcomesByArea = [];
        foreach ($outcomes as $o) {
            if (! is_array($o)) {
                continue;
            }
            $oArea = (string) ($o['area_id'] ?? '');
            if ($oArea === '') {
                continue;
            }
            $outcomesByArea[$oArea][] = (string) ($o['result'] ?? '');
        }

        $findings    = [];
        $mappedIds   = [];
        $areaStateMap = [];

        foreach ($mapEntries as $entry) {
            $areaId              = (string) ($entry['area_id']                ?? '');
            $state               = (string) ($entry['state']                  ?? '');
            $ageDays             = max(0, (int) ($entry['last_updated_age_days'] ?? 0));
            $hasEvidence         = (bool) ($entry['has_completion_evidence']  ?? true);
            $owner               = (string) ($entry['owner']                  ?? '');
            $maturityBand        = (string) ($entry['maturity_band']          ?? '');
            $ownerEvidenceAge    = max(0, (int) ($entry['owner_evidence_age_days'] ?? 0));
            $prevBand            = (string) ($entry['previous_maturity_band'] ?? '');
            $hasFollowUp         = (bool) ($entry['has_follow_up_task']       ?? true);
            $nextLeverage        = (string) ($entry['next_leverage']          ?? '');

            $mappedIds[]           = $areaId;
            $areaStateMap[$areaId] = $state;

            // Confidence reduced by evidence staleness (AC3).
            $confidence = match (true) {
                $ageDays >= 2 * self::STALE_AGE_THRESHOLD_DAYS => 'low',
                $ageDays >= self::STALE_AGE_THRESHOLD_DAYS     => 'medium',
                default                                        => 'high',
            };

            // Recent successful outcome evidence boosts confidence for COMPLETION-class drift only
            // (stale / contradictory) — stale_owner_evidence below intentionally keeps the unboosted
            // $confidence, so stale ownership is never hidden by an unrelated successful run.
            $areaOutcomes = $outcomesByArea[$areaId] ?? [];
            $successCount = count(array_filter($areaOutcomes, static fn (string $r): bool => $r === 'success'));
            $badCount = count(array_filter($areaOutcomes, static fn (string $r): bool => in_array($r, self::BAD_OUTCOME_RESULTS, true)));
            $completionConfidence = $successCount > 0 ? self::CONFIDENCE_BOOST[$confidence] : $confidence;

            $contradictoryFlagged = $state === 'integrated' && ! $hasEvidence;
            if ($contradictoryFlagged) {
                $findings[] = $this->finding($areaId, self::DRIFT_CONTRADICTORY, 'high', $completionConfidence);
            } elseif ($ageDays > self::STALE_AGE_THRESHOLD_DAYS) {
                $findings[] = $this->finding($areaId, self::DRIFT_STALE, 'medium', $completionConfidence);
            }

            // AC2: recent failed/give_back/poison outcomes contradict a claimed advanced/integrated
            // maturity — flag even when the map state itself looks healthy.
            $claimsAdvanced = $state === 'integrated' || in_array($maturityBand, self::ADVANCED_MATURITY_BANDS, true);
            if (! $contradictoryFlagged && $claimsAdvanced && $badCount >= self::OUTCOME_CONTRADICTION_MIN_BAD_COUNT && $badCount > $successCount) {
                $outcomeDrift = $state === 'integrated' ? self::DRIFT_CONTRADICTORY : self::DRIFT_MATURITY_REGRESSION;
                $findings[] = $this->finding($areaId, $outcomeDrift, 'high', 'high');
            }

            if ($owner === '') {
                $findings[] = $this->finding($areaId, self::DRIFT_MISSING_OWNER, 'medium', $confidence);
            }

            if ($maturityBand === '') {
                $findings[] = $this->finding($areaId, self::DRIFT_MISSING_MATURITY_BAND, 'medium', $confidence);
            }

            if ($ownerEvidenceAge > self::STALE_AGE_THRESHOLD_DAYS) {
                $findings[] = $this->finding($areaId, self::DRIFT_STALE_OWNER_EVIDENCE, 'medium', $confidence);
            }

            if ($prevBand !== '' && $maturityBand !== '' && ! $hasFollowUp) {
                $prevOrder = self::MATURITY_BAND_ORDER[$prevBand] ?? -1;
                $currOrder = self::MATURITY_BAND_ORDER[$maturityBand] ?? -1;
                if ($prevOrder > $currOrder) {
                    $findings[] = $this->finding($areaId, self::DRIFT_MATURITY_REGRESSION, 'medium', $confidence);
                }
            }

            // AC2: missing next_leverage for a known domain (AC2).
            if ($nextLeverage === '') {
                $findings[] = $this->finding($areaId, self::DRIFT_MISSING_NEXT_LEVERAGE, 'medium', $confidence);
            }
        }

        foreach ($queuedAreas as $area) {
            if (! in_array($area, $mappedIds, true)) {
                $findings[] = $this->finding($area, self::DRIFT_MISSING, 'high', 'high');
            } elseif (in_array($areaStateMap[$area] ?? '', ['retired', 'blocked'], true)) {
                $findings[] = $this->finding($area, self::DRIFT_RETIRED_BLOCKED_QUEUE, 'high', 'high', $areaStateMap[$area]);
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
    private function finding(string $areaId, string $driftType, string $impactLevel, string $confidence, string $queueState = ''): array
    {
        $repairAction = $driftType === self::DRIFT_RETIRED_BLOCKED_QUEUE
            ? ($queueState === 'retired' ? 'queue_redirect' : 'reactivation_gate')
            : (self::REPAIR_ACTION_BY_DRIFT[$driftType] ?? 'review_finding');

        $evidence = self::EVIDENCE_BY_DRIFT[$driftType];

        return [
            'area_id'             => $areaId,
            'drift_type'          => $driftType,
            'impact_level'        => $impactLevel,
            'impact'              => $impactLevel,
            'confidence'          => $confidence,
            'evidence_needed'     => $evidence,
            'required_evidence'   => $evidence,
            'repair_action'       => $repairAction,
            'task_fabric_advice'  => self::TASK_FABRIC_ADVICE_BY_DRIFT[$driftType] ?? 'proceed_with_caution_pending_repair',
            'enqueue_safe'        => $impactLevel !== 'high',
        ];
    }
}
