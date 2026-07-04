<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

/**
 * Requires the originator to consider simplification, merge, delete, or rescope before accepting
 * a new task batch when the same capability area already has many recent organs or unresolved debt.
 *
 * RETURNS 'accept' ONLY when:
 *   - No consolidation trigger fires for any area touched by the batch, AND
 *   - The batch provides explicit positive capability value (capability_score > 0 on at least one task).
 *
 * RETURNS 'consolidate_first' when any of the following apply:
 *   - duplicate_organs >= 2 in any touched area
 *   - orphaned_integrations >= 3 in any touched area
 *   - backlog_cost >= 25.0 in any touched area
 *   - forecast_confidence < 0.40 in any touched area
 *   - No task in the batch carries positive capability_score (no demonstrated value)
 *
 * INPUT:
 *   candidate_batch: list<{
 *     id: string,
 *     area: string,
 *     capability_score?: float  [0..1],  // 0 or missing = no demonstrated value
 *     is_novel?: bool,
 *   }>
 *
 *   area_debt: map<area, {
 *     recent_organ_count?: int,
 *     duplicate_organs?: int,
 *     orphaned_integrations?: int,
 *     backlog_cost?: float,
 *     forecast_confidence?: float,
 *   }>
 *
 * OUTPUT:
 *   { schema, verdict:'accept'|'consolidate_first',
 *     consolidation_triggers:list<string>, reasons:list<string> }
 *
 * PURE / DETERMINISTIC. No I/O.
 */
final class AtlasTaskFabricConsolidationFirstGate
{
    public const SCHEMA = 'atlas.task_fabric.consolidation_first_gate.v1';

    public const VERDICT_ACCEPT             = 'accept';
    public const VERDICT_CONSOLIDATE_FIRST  = 'consolidate_first';

    // Thresholds that trigger consolidation.
    private const DUPLICATE_ORGANS_THRESHOLD       = 2;
    private const ORPHANED_INTEGRATIONS_THRESHOLD  = 3;
    private const BACKLOG_COST_THRESHOLD           = 25.0;
    private const FORECAST_CONFIDENCE_FLOOR        = 0.40;
    private const PROXY_SCAFFOLD_FLOOR             = 1;

    /** Default evidence floor for all threshold families. */
    private const DEFAULT_EVIDENCE_FLOOR = 1.0;

    /**
     * @param  list<array<string,mixed>>   $candidateBatch
     * @param  array<string,array<string,mixed>>  $areaDebt
     * @param  array<string,mixed>  $options  {evidence_floor?:float}
     * @return array<string,mixed>
     */
    public function evaluate(array $candidateBatch, array $areaDebt, array $options = []): array
    {
        $evidenceFloor = max(0.0, min(1.0, (float) ($options['evidence_floor'] ?? self::DEFAULT_EVIDENCE_FLOOR)));

        $triggers = [];
        $reasons  = [];

        // Collect areas touched by this batch.
        $touchedAreas = [];
        foreach ($candidateBatch as $task) {
            $area = (string) ($task['area'] ?? '');
            if ($area !== '') {
                $touchedAreas[$area] = true;
            }
        }

        // Check debt triggers for each area the batch touches.
        foreach (array_keys($touchedAreas) as $area) {
            $debt = is_array($areaDebt[$area] ?? null) ? (array) $areaDebt[$area] : [];
            $this->checkAreaTriggers($area, $debt, $triggers, $reasons, $evidenceFloor);
        }

        // Check that the batch demonstrates positive capability value.
        $hasDemonstratedValue = $this->batchHasPositiveValue($candidateBatch, $reasons);

        // AC3: critical capability unblock + cleanup follow-through overrides consolidate_first.
        $hasCriticalUnblock = false;
        $cleanupLink = '';
        foreach ($candidateBatch as $task) {
            if ((bool) ($task['unblocks_critical_capability'] ?? false)) {
                $hasCriticalUnblock = true;
                $cleanupLink = (string) ($task['cleanup_follow_through_link'] ?? '');
                if ($cleanupLink !== '') {
                    break;
                }
            }
        }
        $criticalOverride = $hasCriticalUnblock && $cleanupLink !== '';

        // AC4: build consolidation recommendation when rejected.
        $consolidationRecommendation = null;
        if ($triggers !== [] || ! $hasDemonstratedValue) {
            $firstTriggerArea = '';
            $deletionCandidateCount = 0;
            foreach ($touchedAreas as $area => $_) {
                $d = is_array($areaDebt[$area] ?? null) ? (array) $areaDebt[$area] : [];
                $deletionCandidateCount += (int) ($d['duplicate_organs'] ?? 0) + (int) ($d['orphaned_integrations'] ?? 0);
                if ($firstTriggerArea === '') {
                    $firstTriggerArea = $area;
                }
            }

            $consolidationRecommendation = [
                'target_family'            => $firstTriggerArea,
                'deletion_candidate_count' => $deletionCandidateCount,
                'next_refactor_task_shape' => $deletionCandidateCount > 0
                    ? 'merge_or_delete:'.$firstTriggerArea
                    : 'rescue_or_rescope:'.$firstTriggerArea,
            ];
        }

        if (($triggers !== [] || ! $hasDemonstratedValue) && ! $criticalOverride) {
            return $this->result(self::VERDICT_CONSOLIDATE_FIRST, $triggers, $reasons, $consolidationRecommendation);
        }

        if ($criticalOverride) {
            $reasons[] = 'critical_capability_unblock_with_cleanup_follow_through:'.$cleanupLink;
        }

        $reasons[] = sprintf(
            'no_consolidation_triggers:areas=%s',
            implode(',', array_keys($touchedAreas)) ?: 'none',
        );

        return $this->result(self::VERDICT_ACCEPT, $triggers, $reasons);
    }

    /**
     * @param  list<string>  $triggers  (out)
     * @param  list<string>  $reasons   (out)
     */
    private function checkAreaTriggers(string $area, array $debt, array &$triggers, array &$reasons, float $evidenceFloor = 1.0): void
    {
        $duplicateOrgans      = max(0, (int) ($debt['duplicate_organs'] ?? 0));
        $orphanedIntegrations = max(0, (int) ($debt['orphaned_integrations'] ?? 0));
        $backlogCost          = max(0.0, (float) ($debt['backlog_cost'] ?? 0.0));
        $forecastConfidence   = min(1.0, max(0.0, (float) ($debt['forecast_confidence'] ?? 1.0)));
        $proxyScaffolds       = max(0, (int) ($debt['proxy_scaffold_count'] ?? 0));
        $evidenceStrength     = min(1.0, max(0.0, (float) ($debt['evidence_strength'] ?? 1.0)));

        // AC2: evidence floor — if the area's evidence strength is below the configured
        // floor, the gate is more conservative about accepting net-new proposals.
        $belowEvidenceFloor = $evidenceStrength < $evidenceFloor;

        if ($duplicateOrgans >= self::DUPLICATE_ORGANS_THRESHOLD || ($belowEvidenceFloor && $duplicateOrgans > 0)) {
            $triggers[] = 'duplicate_organs:'.$area;
            $reasons[]  = sprintf('area "%s" has %d duplicate organs (threshold %d)', $area, $duplicateOrgans, self::DUPLICATE_ORGANS_THRESHOLD);
        }

        if ($orphanedIntegrations >= self::ORPHANED_INTEGRATIONS_THRESHOLD) {
            $triggers[] = 'orphaned_integrations:'.$area;
            $reasons[]  = sprintf('area "%s" has %d orphaned integrations (threshold %d)', $area, $orphanedIntegrations, self::ORPHANED_INTEGRATIONS_THRESHOLD);
        }

        if ($backlogCost >= self::BACKLOG_COST_THRESHOLD) {
            $triggers[] = 'high_backlog_cost:'.$area;
            $reasons[]  = sprintf('area "%s" backlog_cost=%.1f (threshold %.1f)', $area, $backlogCost, self::BACKLOG_COST_THRESHOLD);
        }

        if ($forecastConfidence < self::FORECAST_CONFIDENCE_FLOOR) {
            $triggers[] = 'low_forecast_confidence:'.$area;
            $reasons[]  = sprintf('area "%s" forecast_confidence=%.2f (floor %.2f)', $area, $forecastConfidence, self::FORECAST_CONFIDENCE_FLOOR);
        }

        // AC2: proxy scaffolds block net-new proposals in the same area.
        if ($proxyScaffolds >= self::PROXY_SCAFFOLD_FLOOR) {
            $triggers[] = 'proxy_scaffolds:'.$area;
            $reasons[]  = sprintf('area "%s" has %d proxy scaffolds (floor %d)', $area, $proxyScaffolds, self::PROXY_SCAFFOLD_FLOOR);
        }

        if ($belowEvidenceFloor) {
            $triggers[] = 'below_evidence_floor:'.$area;
            $reasons[]  = sprintf('area "%s" evidence_strength=%.2f below floor %.2f', $area, $evidenceStrength, $evidenceFloor);
        }
    }

    /**
     * Returns true if at least one task in the batch has capability_score > 0.
     *
     * @param  list<array<string,mixed>>  $candidateBatch
     * @param  list<string>               $reasons  (out)
     */
    private function batchHasPositiveValue(array $candidateBatch, array &$reasons): bool
    {
        if ($candidateBatch === []) {
            $reasons[] = 'empty_batch:no_capability_value_demonstrated';

            return false;
        }

        foreach ($candidateBatch as $task) {
            $score = (float) ($task['capability_score'] ?? 0.0);
            if ($score > 0.0) {
                return true;
            }
        }

        $reasons[] = 'no_positive_capability_score_in_batch';

        return false;
    }

    /**
     * @param  list<string>  $triggers
     * @param  list<string>  $reasons
     * @return array<string,mixed>
     */
    private function result(string $verdict, array $triggers, array $reasons, ?array $consolidationRecommendation = null): array
    {
        sort($triggers, SORT_STRING);
        sort($reasons, SORT_STRING);

        $response = [
            'schema'                  => self::SCHEMA,
            'verdict'                 => $verdict,
            'consolidation_triggers'  => array_values(array_unique($triggers)),
            'reasons'                 => array_values($reasons),
        ];

        if ($consolidationRecommendation !== null) {
            $response['consolidation_first_recommendation'] = $consolidationRecommendation;
        }

        return $response;
    }
}
