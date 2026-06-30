<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Dry-runs a proposed task batch against synthetic queue/worker/gate outcomes before enqueueing.
 * Produces a risk verdict so expensive bad batches are blocked before they start.
 *
 * RISK FLAGS (any one → verdict = risky):
 *   give_back_pressure           — simulated give_back_rate >= give_back_rate_threshold
 *   forbidden_target_pressure    — any packet has is_forbidden_target = true
 *   queue_oversaturation         — batch_size + current_queue_depth > saturation_limit
 *   low_compounding_value        — avg compounding value of batch < compounding_value_floor
 *   insufficient_worker_capacity — worker_capacity < batch_size
 *   write_set_collision          — two or more packets share a file in their write_set
 *   insufficient_verification_evidence — verification_evidence_coverage < verification_evidence_threshold
 *
 * VERDICT = acceptable when zero flags triggered.
 *
 * INPUT:
 *   batch:                          list<{ packet_id, is_forbidden_target?, estimated_compounding_value?, write_set? }>
 *   worker_capacity:                int   (default 0)
 *   current_queue_depth:            int   (default 0)
 *   saturation_limit?:              int   (default 100)
 *   give_back_rate?:                float (default 0.0)
 *   give_back_rate_threshold?:      float (default 0.30)
 *   verification_evidence_coverage?: float (default 1.0)
 *   verification_evidence_threshold?: float (default 0.70)
 *   compounding_value_floor?:       float (default 0.20)
 *
 * OUTPUT:
 *   { schema, verdict, risk_flags, risk_reasons, simulation_summary }
 *
 * PURE / DETERMINISTIC / NO I/O. Does NOT enqueue tasks.
 */
final class AtlasExternalBrainSimulationTwinPlanProbe
{
    public const SCHEMA = 'atlas.external_brain.simulation_twin_plan_probe.v1';

    public const VERDICT_ACCEPTABLE = 'acceptable';
    public const VERDICT_RISKY      = 'risky';

    public const FLAG_GIVE_BACK_PRESSURE             = 'give_back_pressure';
    public const FLAG_FORBIDDEN_TARGET_PRESSURE      = 'forbidden_target_pressure';
    public const FLAG_QUEUE_OVERSATURATION           = 'queue_oversaturation';
    public const FLAG_LOW_COMPOUNDING_VALUE          = 'low_compounding_value';
    public const FLAG_INSUFFICIENT_WORKER_CAPACITY   = 'insufficient_worker_capacity';
    public const FLAG_WRITE_SET_COLLISION            = 'write_set_collision';
    public const FLAG_INSUFFICIENT_VERIFICATION_EVIDENCE = 'insufficient_verification_evidence';

    private const DEFAULT_SATURATION_LIMIT                = 100;
    private const DEFAULT_GIVE_BACK_RATE_THRESHOLD        = 0.30;
    private const DEFAULT_VERIFICATION_EVIDENCE_THRESHOLD = 0.70;
    private const DEFAULT_COMPOUNDING_VALUE_FLOOR         = 0.20;

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function probe(array $input): array
    {
        $batch                   = is_array($input['batch'] ?? null) ? $input['batch'] : [];
        $workerCapacity          = max(0, (int) ($input['worker_capacity'] ?? 0));
        $currentQueueDepth       = max(0, (int) ($input['current_queue_depth'] ?? 0));
        $saturationLimit         = max(1, (int) ($input['saturation_limit'] ?? self::DEFAULT_SATURATION_LIMIT));
        $giveBackRate            = (float) ($input['give_back_rate'] ?? 0.0);
        $giveBackThreshold       = (float) ($input['give_back_rate_threshold'] ?? self::DEFAULT_GIVE_BACK_RATE_THRESHOLD);
        $evidenceCoverage        = (float) ($input['verification_evidence_coverage'] ?? 1.0);
        $evidenceThreshold       = (float) ($input['verification_evidence_threshold'] ?? self::DEFAULT_VERIFICATION_EVIDENCE_THRESHOLD);
        $compoundingFloor        = (float) ($input['compounding_value_floor'] ?? self::DEFAULT_COMPOUNDING_VALUE_FLOOR);

        // Normalise batch packets.
        $packets = [];
        foreach ($batch as $p) {
            if (is_array($p) && isset($p['packet_id'])) {
                $packets[] = $p;
            }
        }

        $batchSize            = count($packets);
        $forbiddenCount       = 0;
        $compoundingTotal     = 0.0;
        $seenFiles            = [];
        $writeSetCollision    = false;

        foreach ($packets as $p) {
            if ((bool) ($p['is_forbidden_target'] ?? false)) {
                $forbiddenCount++;
            }

            $compoundingTotal += (float) ($p['estimated_compounding_value'] ?? 0.0);

            $writeSet = is_array($p['write_set'] ?? null) ? $p['write_set'] : [];
            foreach ($writeSet as $file) {
                $file = (string) $file;
                if (isset($seenFiles[$file])) {
                    $writeSetCollision = true;
                }
                $seenFiles[$file] = true;
            }
        }

        $avgCompounding        = $batchSize > 0 ? $compoundingTotal / $batchSize : 0.0;
        $projectedGiveBackCount = $batchSize > 0 ? (int) round($giveBackRate * $batchSize) : 0;
        $canWorkerHandle       = $workerCapacity >= $batchSize;

        // Accumulate flags.
        $riskFlags   = [];
        $riskReasons = [];

        if ($giveBackRate >= $giveBackThreshold) {
            $riskFlags[]   = self::FLAG_GIVE_BACK_PRESSURE;
            $riskReasons[] = sprintf(
                'give_back_rate=%.2f >= threshold=%.2f',
                $giveBackRate,
                $giveBackThreshold,
            );
        }

        if ($forbiddenCount > 0) {
            $riskFlags[]   = self::FLAG_FORBIDDEN_TARGET_PRESSURE;
            $riskReasons[] = sprintf('forbidden_target_count=%d', $forbiddenCount);
        }

        if ($batchSize + $currentQueueDepth > $saturationLimit) {
            $riskFlags[]   = self::FLAG_QUEUE_OVERSATURATION;
            $riskReasons[] = sprintf(
                'batch_size(%d) + queue_depth(%d) = %d > saturation_limit(%d)',
                $batchSize,
                $currentQueueDepth,
                $batchSize + $currentQueueDepth,
                $saturationLimit,
            );
        }

        if ($batchSize > 0 && $avgCompounding < $compoundingFloor) {
            $riskFlags[]   = self::FLAG_LOW_COMPOUNDING_VALUE;
            $riskReasons[] = sprintf(
                'avg_compounding_value=%.3f < floor=%.3f',
                $avgCompounding,
                $compoundingFloor,
            );
        }

        if ($batchSize > 0 && ! $canWorkerHandle) {
            $riskFlags[]   = self::FLAG_INSUFFICIENT_WORKER_CAPACITY;
            $riskReasons[] = sprintf(
                'worker_capacity=%d < batch_size=%d',
                $workerCapacity,
                $batchSize,
            );
        }

        if ($writeSetCollision) {
            $riskFlags[]   = self::FLAG_WRITE_SET_COLLISION;
            $riskReasons[] = 'two or more packets share files in their write_set';
        }

        if ($batchSize > 0 && $evidenceCoverage < $evidenceThreshold) {
            $riskFlags[]   = self::FLAG_INSUFFICIENT_VERIFICATION_EVIDENCE;
            $riskReasons[] = sprintf(
                'verification_evidence_coverage=%.2f < threshold=%.2f',
                $evidenceCoverage,
                $evidenceThreshold,
            );
        }

        return [
            'schema'      => self::SCHEMA,
            'verdict'     => $riskFlags === [] ? self::VERDICT_ACCEPTABLE : self::VERDICT_RISKY,
            'risk_flags'  => $riskFlags,
            'risk_reasons' => $riskReasons,
            'simulation_summary' => [
                'batch_size'                => $batchSize,
                'forbidden_target_count'    => $forbiddenCount,
                'write_set_collision_count' => $writeSetCollision ? 1 : 0,
                'projected_give_back_count' => $projectedGiveBackCount,
                'can_worker_handle'         => $canWorkerHandle,
                'avg_compounding_value'     => $avgCompounding,
            ],
        ];
    }
}
