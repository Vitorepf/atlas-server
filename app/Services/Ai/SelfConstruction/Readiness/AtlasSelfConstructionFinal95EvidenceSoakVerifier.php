<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness;

/**
 * Pure soak verifier. Evaluates a bounded window of evidence samples and
 * determines whether final self-construction readiness is satisfied.
 *
 * Per-sample failure checks (AC2 — checked in priority order):
 *   1. is_recovery_mode === true         → recovery_flagged
 *   2. evidence_refs empty or absent     → malformed_evidence
 *   3. worker_health === 'failed'        → worker_failure
 *   4. queue_pressure >= 0.70            → high_queue_pressure
 *   5. merge_success_rate < 0.80        → low_merge_success_rate
 *   6. stale: age_seconds > freshness_threshold_hours × 3600 → stale_evidence
 *
 * Window-level check:
 *   sample_count < min_sample_count     → insufficient_samples (→ not ready)
 *
 * AC3: ready === true only when sample_count >= min_sample_count AND
 *   every sample passes all checks (failing_samples is empty).
 *   Weakest failure = the first sample failure in priority order.
 *
 * AC4 outputs: ready, soak_window, failing_samples, required_repairs, evidence_summary.
 *
 * Pure, deterministic, no providers, no I/O.
 */
final class AtlasSelfConstructionFinal95EvidenceSoakVerifier
{
    public const SCHEMA = 'atlas.self_construction.readiness.final_95_evidence_soak_verifier.v1';

    private const QUEUE_PRESSURE_CAP      = 0.70;
    private const MERGE_SUCCESS_FLOOR     = 0.80;
    private const DEFAULT_MIN_SAMPLES     = 5;
    private const DEFAULT_FRESHNESS_HOURS = 24;

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function verify(array $facts): array
    {
        $samples          = is_array($facts['soak_samples'] ?? null) ? $facts['soak_samples'] : [];
        $soakHours        = max(1, (int) ($facts['soak_window_hours']       ?? 24));
        $minSamples       = max(1, (int) ($facts['min_sample_count']         ?? self::DEFAULT_MIN_SAMPLES));
        $freshnessHours   = max(1, (int) ($facts['freshness_threshold_hours'] ?? self::DEFAULT_FRESHNESS_HOURS));
        $now              = (int) ($facts['current_timestamp'] ?? 0);
        $freshnessSeconds = $freshnessHours * 3600;

        $sampleCount    = count($samples);
        $failingSamples = [];
        $summaryHealthy = 0;
        $summaryFailing = 0;
        $summaryRecovery = 0;
        $summaryStale   = 0;
        $allTimestamps  = [];

        foreach ($samples as $sample) {
            $id             = (string)  ($sample['id']                ?? '');
            $ts             = (int)     ($sample['timestamp']         ?? 0);
            $pressure       = max(0.0, min(1.0, (float) ($sample['queue_pressure']      ?? 0.0)));
            $workerHealth   = strtolower(trim((string) ($sample['worker_health']   ?? 'healthy')));
            $mergeRate      = max(0.0, min(1.0, (float) ($sample['merge_success_rate']   ?? 1.0)));
            $isRecovery     = (bool) ($sample['is_recovery_mode']  ?? false);
            $evidenceRefs   = array_values((array) ($sample['evidence_refs'] ?? []));

            $allTimestamps[] = $ts;
            $failures        = [];

            // 1. Recovery mode (AC2).
            if ($isRecovery) {
                $failures[] = 'recovery_flagged';
                $summaryRecovery++;
            }

            // 2. Malformed evidence (AC2).
            if (empty($evidenceRefs)) {
                $failures[] = 'malformed_evidence';
            }

            // 3. Worker failure.
            if ($workerHealth === 'failed') {
                $failures[] = 'worker_failure';
            }

            // 4. High queue pressure.
            if ($pressure >= self::QUEUE_PRESSURE_CAP) {
                $failures[] = 'high_queue_pressure';
            }

            // 5. Low merge success rate.
            if ($mergeRate < self::MERGE_SUCCESS_FLOOR) {
                $failures[] = 'low_merge_success_rate';
            }

            // 6. Stale evidence.
            if ($now > 0 && ($now - $ts) > $freshnessSeconds) {
                $failures[] = 'stale_evidence';
                $summaryStale++;
            }

            if (empty($failures)) {
                $summaryHealthy++;
            } else {
                $failingSamples[] = ['id' => $id, 'failure_reasons' => $failures];
                $summaryFailing++;
            }
        }

        // Window-level assessment.
        $insufficientSamples = $sampleCount < $minSamples;
        $ready               = ! $insufficientSamples && empty($failingSamples);

        // Required repairs: unique failure reason strings across all failing samples,
        // plus insufficient_samples if applicable.
        $repairSet = [];
        foreach ($failingSamples as $fs) {
            foreach ($fs['failure_reasons'] as $reason) {
                $repairSet[$reason] = true;
            }
        }
        if ($insufficientSamples) {
            $repairSet['insufficient_samples'] = true;
        }

        $soakWindow = [
            'hours'           => $soakHours,
            'sample_count'    => $sampleCount,
            'start_timestamp' => $allTimestamps !== [] ? min($allTimestamps) : 0,
            'end_timestamp'   => $allTimestamps !== [] ? max($allTimestamps) : 0,
        ];

        return [
            'schema_version'   => self::SCHEMA,
            'ready'            => $ready,
            'soak_window'      => $soakWindow,
            'failing_samples'  => $failingSamples,
            'required_repairs' => array_values(array_keys($repairSet)),
            'evidence_summary' => [
                'healthy_count'       => $summaryHealthy,
                'failing_count'       => $summaryFailing,
                'recovery_mode_count' => $summaryRecovery,
                'stale_count'         => $summaryStale,
            ],
        ];
    }
}
