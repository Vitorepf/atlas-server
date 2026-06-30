<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Health;

/**
 * Pure diagnostic lens explaining WHY claimable tasks are aging without being drained.
 * Distinguishes healthy backlog depth from stale-oldest starvation, suspected stuck leases, low
 * worker consumption, task-family avoidance, and near-expiry lease pressure — so the next action
 * is evidence-based, never a guess.
 *
 * Input shape: {claimable_depth?:int, oldest_age_p95_seconds?:int, stale_threshold_seconds?:int,
 *               active_workers?:int, observed_consumption_count?:int, near_expiry_lease_count?:int,
 *               suspected_stuck_lease_count?:int, avoided_family_signals?:list<string>}
 *
 * Pure read-only PHP array analysis — no live queue, provider, process, filesystem, DB, or git side
 * effects.
 */
final class AtlasMaestroStaleClaimableRescueLens
{
    public const SCHEMA = 'atlas.self_construction.maestro.stale_claimable_rescue_lens.v1';

    public const DIAGNOSIS_HEALTHY_BACKLOG_DEPTH = 'healthy_backlog_depth';

    public const DIAGNOSIS_STALE_CLAIMABLE_BACKLOG_NEEDS_ROTATION = 'stale_claimable_backlog_needs_rotation';

    public const DIAGNOSIS_SUSPECTED_STUCK_LEASES = 'suspected_stuck_leases';

    public const DIAGNOSIS_LOW_WORKER_CONSUMPTION = 'low_worker_consumption';

    public const DIAGNOSIS_TASK_FAMILY_AVOIDANCE = 'task_family_avoidance';

    public const DIAGNOSIS_NEAR_EXPIRY_LEASE_PRESSURE = 'near_expiry_lease_pressure';

    public const DIAGNOSIS_INSUFFICIENT_EVIDENCE = 'insufficient_evidence';

    public const SEVERITY_NONE = 'none';

    public const SEVERITY_LOW = 'low';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_HIGH = 'high';

    private const DEFAULT_STALE_THRESHOLD_SECONDS = 3600;

    /**
     * @param  array<string,mixed>  $facts
     * @return array{schema:string, diagnosis:string, severity:string, likely_cause:string, rescue_actions:list<string>, evidence:list<string>}
     */
    public function diagnose(array $facts): array
    {
        $claimableDepth = max(0, (int) ($facts['claimable_depth'] ?? 0));
        $oldestAgeP95 = max(0, (int) ($facts['oldest_age_p95_seconds'] ?? 0));
        $staleThreshold = max(1, (int) ($facts['stale_threshold_seconds'] ?? self::DEFAULT_STALE_THRESHOLD_SECONDS));
        $suspectedStuckLeaseCount = max(0, (int) ($facts['suspected_stuck_lease_count'] ?? 0));
        $nearExpiryLeaseCount = max(0, (int) ($facts['near_expiry_lease_count'] ?? 0));
        $avoidedFamilySignals = array_values(array_unique(array_map('strval', (array) ($facts['avoided_family_signals'] ?? []))));

        $consumptionProvided = array_key_exists('observed_consumption_count', $facts);
        $observedConsumption = $consumptionProvided ? max(0, (int) $facts['observed_consumption_count']) : null;

        $workersProvided = array_key_exists('active_workers', $facts);
        $activeWorkers = $workersProvided ? max(0, (int) $facts['active_workers']) : null;

        $isStale = $oldestAgeP95 >= $staleThreshold;
        $hasNoObservedConsumption = $observedConsumption === 0;
        $hasDeepBacklog = $claimableDepth > 0;

        $evidence = [];
        $rescueActions = [];

        if ($suspectedStuckLeaseCount > 0) {
            $diagnosis = self::DIAGNOSIS_SUSPECTED_STUCK_LEASES;
            $severity = self::SEVERITY_HIGH;
            $likelyCause = sprintf('%d lease(s) suspected stuck (claimed but not progressing)', $suspectedStuckLeaseCount);
            $evidence[] = 'suspected_stuck_lease_count='.$suspectedStuckLeaseCount;
            $rescueActions = ['reap_suspected_stuck_leases', 'audit_worker_health'];
        } elseif ($hasDeepBacklog && $isStale && $hasNoObservedConsumption) {
            $diagnosis = self::DIAGNOSIS_STALE_CLAIMABLE_BACKLOG_NEEDS_ROTATION;
            $severity = self::SEVERITY_HIGH;
            $likelyCause = 'claimable backlog is deep, oldest items exceed the staleness threshold, and no consumption was observed';
            $evidence[] = 'claimable_depth='.$claimableDepth;
            $evidence[] = 'oldest_age_p95_seconds='.$oldestAgeP95;
            $evidence[] = 'observed_consumption_count=0';
            $rescueActions = ['rotate_stale_claimable_to_front', 'verify_worker_loop_alive'];
        } elseif ($avoidedFamilySignals !== []) {
            $diagnosis = self::DIAGNOSIS_TASK_FAMILY_AVOIDANCE;
            $severity = self::SEVERITY_MEDIUM;
            $likelyCause = 'specific task families are being repeatedly skipped or given back: '.implode(', ', $avoidedFamilySignals);
            $evidence[] = 'avoided_family_signals='.implode(',', $avoidedFamilySignals);
            $rescueActions = ['respec_avoided_families', 'route_to_capable_workers'];
        } elseif ($nearExpiryLeaseCount > 0) {
            $diagnosis = self::DIAGNOSIS_NEAR_EXPIRY_LEASE_PRESSURE;
            $severity = self::SEVERITY_MEDIUM;
            $likelyCause = sprintf('%d lease(s) nearing expiry, risk of a reclaim/churn storm', $nearExpiryLeaseCount);
            $evidence[] = 'near_expiry_lease_count='.$nearExpiryLeaseCount;
            $rescueActions = ['extend_or_renew_near_expiry_leases', 'monitor_reclaim_rate'];
        } elseif ($workersProvided && $activeWorkers === 0 && $hasDeepBacklog) {
            // Workers are blamed only when active_workers was EXPLICITLY reported as zero —
            // never inferred from missing data.
            $diagnosis = self::DIAGNOSIS_LOW_WORKER_CONSUMPTION;
            $severity = self::SEVERITY_HIGH;
            $likelyCause = 'no active workers observed while a claimable backlog exists';
            $evidence[] = 'active_workers=0';
            $evidence[] = 'claimable_depth='.$claimableDepth;
            $rescueActions = ['spawn_or_revive_workers'];
        } elseif (! $consumptionProvided) {
            $diagnosis = self::DIAGNOSIS_INSUFFICIENT_EVIDENCE;
            $severity = self::SEVERITY_LOW;
            $likelyCause = 'observed_consumption_count was not provided; cannot distinguish backlog health from worker idleness without it';
            $evidence[] = 'observed_consumption_count_missing';
            $rescueActions = ['collect_consumption_window_metrics'];
        } else {
            $diagnosis = self::DIAGNOSIS_HEALTHY_BACKLOG_DEPTH;
            $severity = self::SEVERITY_NONE;
            $likelyCause = 'claimable depth and age are within healthy bounds and consumption is observed';
            $evidence[] = 'claimable_depth='.$claimableDepth;
            $evidence[] = 'oldest_age_p95_seconds='.$oldestAgeP95;
            $rescueActions = [];
        }

        return [
            'schema' => self::SCHEMA,
            'diagnosis' => $diagnosis,
            'severity' => $severity,
            'likely_cause' => $likelyCause,
            'rescue_actions' => $rescueActions,
            'evidence' => $evidence,
        ];
    }
}
