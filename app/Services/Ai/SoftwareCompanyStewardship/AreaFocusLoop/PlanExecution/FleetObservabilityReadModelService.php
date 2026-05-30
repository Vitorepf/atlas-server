<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution;

/**
 * Axis N · Fleet OBSERVABILITY READ MODEL (pure read; never mutates, never merges).
 *
 * Composes three append-only authorities into one enterprise read model for a fleet run:
 *   1. {@see FleetAuditLedgerService::replay()} — fleet lifecycle (batches, claims, merges,
 *      deferrals, reclaimable orphans).
 *   2. {@see PlanCompletionTrackerService::rollup()} — the SOLE authority on real delivery
 *      (product merges = delivered_count, with provider-proof + acceptance).
 *   3. {@see MetricLedgerService} JSONL — Fase 5 measured-or-reverted outcomes
 *      (outcome_met vs outcome_not_met / measurement_failed).
 *
 * It reports, with NO opinion and NO fabrication:
 *   - throughput            : merges per planned batch (and per claimed worker).
 *   - parallel_utilization  : average batch size vs the configured max_parallel (1.0 = the
 *                             fleet is saturating its concurrency budget; <1 = serialized by
 *                             dependencies/conflicts, which is honest, not a failure).
 *   - conflicts_deferred    : how many slices were deferred (conflict or panel refutation).
 *   - product_merges        : the tracker's delivered_count (real, provider-proven merges).
 *   - measured_or_reverted  : Fase 5 counts — measured/met/not_met/failed — so a fleet that
 *                             merged without moving its declared outcome is visible.
 *
 * Honesty floor: product_merges comes ONLY from the tracker (never from the audit ledger's
 * merged count), so a dishonest audit row can never inflate the delivery number. The two are
 * reported side-by-side with an `audit_vs_tracker_consistent` flag for drift detection.
 */
final class FleetObservabilityReadModelService
{
    public const SCHEMA = 'atlas.axis_n.fleet_observability.v1';

    private FleetAuditLedgerService $audit;

    private PlanCompletionTrackerService $tracker;

    private MetricLedgerService $metrics;

    public function __construct(
        ?FleetAuditLedgerService $audit = null,
        ?PlanCompletionTrackerService $tracker = null,
        ?MetricLedgerService $metrics = null,
    ) {
        $this->audit = $audit ?? new FleetAuditLedgerService;
        $this->tracker = $tracker ?? new PlanCompletionTrackerService;
        $this->metrics = $metrics ?? new MetricLedgerService;
    }

    /**
     * Build the read model for one plan/area from the live ledgers + decomposed plan.
     *
     * @param  array<string,mixed>  $decomposedPlan  decomposed_plan.v1 (for the tracker rollup)
     * @return array<string,mixed>                   fleet_observability.v1
     */
    public function report(string $planId, string $areaId, array $decomposedPlan): array
    {
        $replay = $this->audit->replay($planId, $areaId);
        $rollup = $this->tracker->rollup($planId, $areaId, $decomposedPlan);
        $outcomes = $this->measuredOutcomes($areaId);

        $batchesPlanned = (int) $replay['batches_planned'];
        $workersClaimed = (int) $replay['workers_claimed'];
        $auditMerged = (int) $replay['merged_count'];
        $conflictsDeferred = (int) $replay['deferred_count'];
        $productMerges = (int) ($rollup['delivered_count'] ?? 0);
        $totalSlices = (int) ($rollup['total_slices'] ?? 0);

        $batchSizes = array_map('intval', (array) $replay['batch_sizes']);
        $avgBatchSize = $batchesPlanned > 0 ? array_sum($batchSizes) / $batchesPlanned : 0.0;
        $maxParallel = max(1, (int) $replay['max_parallel_seen']);

        return [
            'schema_version' => self::SCHEMA,
            'plan_id' => $planId,
            'area_id' => $areaId,

            // --- throughput ---
            'throughput' => [
                'batches_planned' => $batchesPlanned,
                'workers_claimed' => $workersClaimed,
                'workers_returned' => (int) $replay['workers_returned'],
                'product_merges' => $productMerges,
                'merges_per_batch' => $batchesPlanned > 0 ? round($productMerges / $batchesPlanned, 3) : 0.0,
                'merges_per_worker' => $workersClaimed > 0 ? round($productMerges / $workersClaimed, 3) : 0.0,
            ],

            // --- parallel utilization ---
            'parallel_utilization' => [
                'max_parallel' => $maxParallel,
                'avg_batch_size' => round($avgBatchSize, 3),
                'utilization_ratio' => round($avgBatchSize / $maxParallel, 3),
                'batch_sizes' => $batchSizes,
            ],

            // --- conflicts deferred ---
            'conflicts_deferred' => [
                'deferred_count' => $conflictsDeferred,
                'deferred_slice_ids' => array_values((array) $replay['deferred_slice_ids']),
                'no_progress_count' => (int) $replay['no_progress_count'],
            ],

            // --- product merges (tracker = authority) + drift check vs the audit ledger ---
            'product_merges' => [
                'delivered_count' => $productMerges,
                'total_slices' => $totalSlices,
                'completion_pct' => (float) ($rollup['completion_pct'] ?? 0.0),
                'ledger_status' => (string) ($rollup['status'] ?? ''),
                'audit_merged_count' => $auditMerged,
                'audit_vs_tracker_consistent' => $auditMerged === $productMerges,
            ],

            // --- Fase 5 measured-or-reverted ---
            'measured_or_reverted' => $outcomes,

            // --- crash-recovery posture (orphaned worker leases awaiting reclaim) ---
            'crash_recovery' => [
                'reclaimable_slice_ids' => array_values((array) $replay['reclaimable_slice_ids']),
                'reclaimable_count' => count((array) $replay['reclaimable_slice_ids']),
                'audit_corrupted_lines' => (int) $replay['corrupted_lines'],
            ],
        ];
    }

    /**
     * Fold the Fase 5 metric-outcome ledger JSONL for an area into measured-or-reverted counts.
     * Pure read; tolerant of a missing/empty ledger (returns zeros, never fabricates).
     *
     * @return array{measured:int,outcome_met:int,outcome_not_met:int,measurement_failed:int,reverted_candidates:int}
     */
    private function measuredOutcomes(string $areaId): array
    {
        $path = $this->metrics->ledgerPath($areaId);
        $measured = 0;
        $met = 0;
        $notMet = 0;
        $failed = 0;

        if (is_file($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $decoded = json_decode($line, true);
                if (! is_array($decoded)) {
                    continue;
                }
                $status = (string) ($decoded['status'] ?? '');
                if ($status === '') {
                    continue;
                }
                $measured++;
                if ($status === MetricLedgerService::STATUS_OUTCOME_MET) {
                    $met++;
                } elseif ($status === MetricLedgerService::STATUS_OUTCOME_NOT_MET) {
                    $notMet++;
                } elseif ($status === MetricLedgerService::STATUS_MEASUREMENT_FAILED) {
                    $failed++;
                }
            }
        }

        return [
            'measured' => $measured,
            'outcome_met' => $met,
            'outcome_not_met' => $notMet,
            'measurement_failed' => $failed,
            // not_met + failed are the merges that, under measured-or-reverted, must revert.
            'reverted_candidates' => $notMet + $failed,
        ];
    }
}
