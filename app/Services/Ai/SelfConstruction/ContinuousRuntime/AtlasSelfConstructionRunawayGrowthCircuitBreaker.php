<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ContinuousRuntime;

/**
 * Pure circuit breaker. Evaluates rolling growth metrics and trips when
 * autonomous origination is adding code/tasks faster than integration proof
 * or real capability sampling can validate them.
 *
 * Trip condition priority (first match wins):
 *   1. missing_proof_metric_with_growth
 *      Fail-closed: value_proof_density key absent from input AND any growth detected.
 *   2. thin_integration_proof_with_growth            (AC2 primary)
 *      integration_proof_count < MIN_INTEGRATION_PROOFS AND any growth present.
 *   3. thin_capability_sampling_with_high_growth     (AC2 secondary)
 *      capability_sampling_coverage < MIN_SAMPLING AND files_added > FILES_THRESHOLD.
 *   4. orphan_accumulation_without_proof
 *      orphaned_organs > ORPHAN_THRESHOLD AND value_proof_density < VALUE_PROOF_MIN.
 *   5. growth_without_value_proof
 *      files_added > FILES_THRESHOLD AND value_proof_density < VALUE_PROOF_MIN.
 *
 * Backpressure actions: pause_origination (highest), throttle_task_intake,
 *                       require_proof_before_next_batch.
 *
 * TRIPPED = breaker open = flow blocked = origination paused (safe state).
 *
 * NO process execution, NO filesystem, NO providers. DETERMINISTIC.
 */
final class AtlasSelfConstructionRunawayGrowthCircuitBreaker
{
    public const SCHEMA = 'atlas.self_construction.runaway_growth_circuit_breaker.v1';

    private const MIN_INTEGRATION_PROOFS = 3;

    private const MIN_SAMPLING           = 0.25;

    private const FILES_THRESHOLD        = 20;

    private const ORPHAN_THRESHOLD       = 20;

    private const VALUE_PROOF_MIN        = 0.3;

    /**
     * @param  array<string,mixed>  $metrics
     * @return array<string,mixed>
     */
    public function evaluate(array $metrics): array
    {
        $filesAdded          = (int) ($metrics['files_added_rolling'] ?? 0);
        $taskDelta           = (int) ($metrics['task_count_delta'] ?? 0);
        $orphanedOrgans      = (int) ($metrics['orphaned_organs_count'] ?? 0);
        $integrationProofs   = (int) ($metrics['integration_proof_count'] ?? 0);
        $valueDensity        = (float) ($metrics['value_proof_density'] ?? 0.0);
        $samplCoverage       = (float) ($metrics['capability_sampling_coverage'] ?? 0.0);
        $backlogCost         = (int) ($metrics['backlog_cost_rolling'] ?? 0);

        $hasGrowth = $filesAdded > 0 || $taskDelta > 0;

        [$tripped, $tripReason] = $this->firstTripReason(
            $metrics, $hasGrowth, $filesAdded, $taskDelta,
            $orphanedOrgans, $integrationProofs, $valueDensity, $samplCoverage,
        );

        return [
            'schema_version'      => self::SCHEMA,
            'tripped'             => $tripped,
            'trip_reason'         => $tripReason,
            'backpressure_action' => $tripped ? $this->backpressureAction($tripReason) : null,
            'diagnostics' => [
                'files_added_rolling'          => $filesAdded,
                'task_count_delta'             => $taskDelta,
                'orphaned_organs_count'        => $orphanedOrgans,
                'integration_proof_count'      => $integrationProofs,
                'value_proof_density'          => $valueDensity,
                'capability_sampling_coverage' => $samplCoverage,
                'backlog_cost_rolling'         => $backlogCost,
                'has_growth'                   => $hasGrowth,
            ],
        ];
    }

    /**
     * @return array{bool, string|null}
     */
    private function firstTripReason(
        array $metrics, bool $hasGrowth,
        int $filesAdded, int $taskDelta,
        int $orphanedOrgans, int $integrationProofs,
        float $valueDensity, float $samplCoverage,
    ): array {
        // 1. Fail-closed: missing proof metric while growth is occurring.
        if ($hasGrowth && ! array_key_exists('value_proof_density', $metrics)) {
            return [true, 'missing_proof_metric_with_growth'];
        }

        // 2. Thin integration proof while growth is occurring (AC2).
        if ($hasGrowth && $integrationProofs < self::MIN_INTEGRATION_PROOFS) {
            return [true, 'thin_integration_proof_with_growth'];
        }

        // 3. Thin capability sampling during high growth (AC2).
        if ($filesAdded > self::FILES_THRESHOLD && $samplCoverage < self::MIN_SAMPLING) {
            return [true, 'thin_capability_sampling_with_high_growth'];
        }

        // 4. Orphan accumulation without value proof.
        if ($orphanedOrgans > self::ORPHAN_THRESHOLD && $valueDensity < self::VALUE_PROOF_MIN) {
            return [true, 'orphan_accumulation_without_proof'];
        }

        // 5. Raw growth without value proof.
        if ($filesAdded > self::FILES_THRESHOLD && $valueDensity < self::VALUE_PROOF_MIN) {
            return [true, 'growth_without_value_proof'];
        }

        return [false, null];
    }

    private function backpressureAction(?string $tripReason): string
    {
        return match ($tripReason) {
            'missing_proof_metric_with_growth',
            'thin_integration_proof_with_growth',
            'thin_capability_sampling_with_high_growth' => 'pause_origination',
            'orphan_accumulation_without_proof'         => 'throttle_task_intake',
            default                                      => 'require_proof_before_next_batch',
        };
    }
}
